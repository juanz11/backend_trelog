<?php

namespace App\Services;

use App\Exceptions\ManifestException;
use App\Models\Manifest;
use App\Models\ManifestDetail;
use App\Models\ManifestHandoverScan;
use App\Models\ManifestTransferRequest;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Reglas de negocio del ciclo de vida del manifiesto (espejo de
 * src/lib/manifestFlow.js del frontend). Toda transición pasa por aquí.
 */
class ManifestService
{
    private const TRANSICIONES = [
        'asignar' => [Manifest::STATUS_CREADO],
        'rechazarAsignacion' => [Manifest::STATUS_ASIGNADO],
        'iniciarRuta' => [Manifest::STATUS_ASIGNADO],
        'solicitarTraspaso' => [Manifest::STATUS_EN_RUTA],
        'rechazarTraspaso' => [Manifest::STATUS_EN_SOLICITUD_TRASPASO],
        'aprobarTraspaso' => [Manifest::STATUS_EN_SOLICITUD_TRASPASO],
        'finalizar' => [Manifest::STATUS_EN_RUTA],
    ];

    private function assertTransition(Manifest $manifest, string $evento): void
    {
        $desde = self::TRANSICIONES[$evento] ?? [];
        if (! in_array($manifest->status, $desde, true)) {
            throw new ManifestException(
                'TRANSICION_INVALIDA',
                "No se puede '{$evento}' un manifiesto en estado {$manifest->status}"
            );
        }
    }

    private function assertEnRuta(Manifest $manifest): void
    {
        if ($manifest->status !== Manifest::STATUS_EN_RUTA) {
            throw new ManifestException('MANIFIESTO_NO_EN_RUTA', 'El manifiesto no está en ruta');
        }
    }

    /* ---------------------------------------------------------------- */
    /* Creación y asignación                                             */
    /* ---------------------------------------------------------------- */

    /**
     * stops: [{tracking_code, shipment_id?}] en orden de visita.
     */
    public function create(array $stops, ?User $creator, ?int $originId = null, ?string $code = null): Manifest
    {
        return DB::transaction(function () use ($stops, $creator, $originId, $code) {
            $manifest = Manifest::create([
                'code' => $code ?: 'MAN-'.now()->format('ymd').'-'.strtoupper(bin2hex(random_bytes(2))),
                'status' => Manifest::STATUS_CREADO,
                'created_by' => $creator?->id,
                'origin_manifest_id' => $originId,
            ]);

            foreach (array_values($stops) as $i => $stop) {
                $tracking = $stop['tracking_code'];
                $manifest->details()->create([
                    'sequence' => $i + 1,
                    'tracking_code' => $tracking,
                    'shipment_id' => $stop['shipment_id']
                        ?? Shipment::where('tracking_number', $tracking)->value('id'),
                    'origin_detail_id' => $stop['origin_detail_id'] ?? null,
                ]);
            }

            return $manifest->fresh('details');
        });
    }

    /** Operador asigna chofer + vehículo (CREADO → ASIGNADO). */
    public function assign(Manifest $manifest, int $driverId, ?int $vehicleId): Manifest
    {
        $this->assertTransition($manifest, 'asignar');
        if (! $vehicleId) {
            throw new ManifestException('ASIGNACION_INCOMPLETA', 'Asignar requiere chofer y vehículo');
        }
        $manifest->update([
            'status' => Manifest::STATUS_ASIGNADO,
            'driver_id' => $driverId,
            'vehicle_id' => $vehicleId,
            'assignment_rejection_reason' => null,
        ]);

        return $manifest->fresh();
    }

    /** El chofer rechaza la oferta: vuelve a CREADO sin chofer/vehículo. */
    public function rejectAssignment(Manifest $manifest, ?string $reason): Manifest
    {
        $this->assertTransition($manifest, 'rechazarAsignacion');
        $manifest->update([
            'status' => Manifest::STATUS_CREADO,
            'driver_id' => null,
            'vehicle_id' => null,
            'assignment_rejection_reason' => $reason,
        ]);

        return $manifest->fresh();
    }

    public function startRoute(Manifest $manifest): Manifest
    {
        $this->assertTransition($manifest, 'iniciarRuta');
        $manifest->update([
            'status' => Manifest::STATUS_EN_RUTA,
            'started_at' => now(),
        ]);

        return $manifest->fresh();
    }

    /* ---------------------------------------------------------------- */
    /* POD                                                               */
    /* ---------------------------------------------------------------- */

    /** Próxima parada pendiente (menor sequence sin resolver). */
    public function nextStop(Manifest $manifest): ?ManifestDetail
    {
        return $manifest->details()
            ->where('status', ManifestDetail::STATUS_PENDIENTE)
            ->orderBy('sequence')
            ->first();
    }

    /**
     * Valida un código escaneado (QR) o digitado (fallback manual).
     * strict=true exige que corresponda a la parada actual.
     */
    public function validateScan(Manifest $manifest, string $code, bool $strict = true): ManifestDetail
    {
        $this->assertEnRuta($manifest);

        $detail = $manifest->details()
            ->where('tracking_code', trim($code))
            ->first();

        if (! $detail) {
            throw new ManifestException('QR_NO_PERTENECE', 'El paquete no pertenece al manifiesto activo');
        }
        if ($detail->status !== ManifestDetail::STATUS_PENDIENTE) {
            throw new ManifestException('YA_REGISTRADO', "El paquete ya figura como {$detail->status}");
        }
        $next = $this->nextStop($manifest);
        if ($strict && $next && $detail->id !== $next->id) {
            throw new ManifestException('PARADA_INCORRECTA', 'El paquete no coincide con la parada actual');
        }

        return $detail;
    }

    /**
     * Entrega exitosa. Requiere evidencia; guarda GPS/hora/vía de captura.
     * meta: evidence_url?, evidence_path?, lat, lng, gps_accuracy, visited_at, via
     */
    public function deliver(Manifest $manifest, ManifestDetail $detail, array $meta): Manifest
    {
        $this->assertEnRuta($manifest);
        if ($detail->manifest_id !== $manifest->id) {
            throw new ManifestException('DETALLE_NO_EXISTE', 'Detalle no encontrado');
        }
        if ($detail->status !== ManifestDetail::STATUS_PENDIENTE) {
            throw new ManifestException('YA_REGISTRADO', 'La parada ya fue resuelta');
        }
        $evidenceUrl = $meta['evidence_url'] ?? $meta['evidence_path'] ?? null;
        if (! $evidenceUrl) {
            throw new ManifestException('SIN_EVIDENCIA', 'El POD requiere fotografía de evidencia');
        }

        $detail->update([
            'status' => ManifestDetail::STATUS_ENTREGADO,
            'evidence_url' => $meta['evidence_url'] ?? null,
            'evidence_path' => $meta['evidence_path'] ?? null,
            'capture_via' => $meta['via'] ?? ManifestDetail::VIA_QR,
            'lat' => $meta['lat'] ?? null,
            'lng' => $meta['lng'] ?? null,
            'gps_accuracy' => $meta['gps_accuracy'] ?? null,
            'visited_at' => $meta['visited_at'] ?? now(),
        ]);
        $detail->shipment?->update(['status' => 'delivered']);

        return $manifest->fresh('details');
    }

    /** Escanea/digita código → valida → registra entrega. */
    public function deliverByCode(Manifest $manifest, string $code, array $meta): Manifest
    {
        $detail = $this->validateScan($manifest, $code, ($meta['strict'] ?? true) === true);

        return $this->deliver($manifest, $detail, $meta);
    }

    /** Entrega fallida: causa obligatoria (NO_ENTREGADO). */
    public function fail(Manifest $manifest, ManifestDetail $detail, string $reason, array $meta = []): Manifest
    {
        $this->assertEnRuta($manifest);
        if (! $reason) {
            throw new ManifestException('SIN_MOTIVO', 'Registrar incidencia requiere la causa');
        }
        if ($detail->manifest_id !== $manifest->id) {
            throw new ManifestException('DETALLE_NO_EXISTE', 'Detalle no encontrado');
        }
        if ($detail->status !== ManifestDetail::STATUS_PENDIENTE) {
            throw new ManifestException('YA_REGISTRADO', 'La parada ya fue resuelta');
        }

        $detail->update([
            'status' => ManifestDetail::STATUS_NO_ENTREGADO,
            'failure_reason' => $reason,
            'lat' => $meta['lat'] ?? null,
            'lng' => $meta['lng'] ?? null,
            'visited_at' => now(),
        ]);
        $detail->shipment?->update(['status' => 'incident']);

        return $manifest->fresh('details');
    }

    /* ---------------------------------------------------------------- */
    /* Cierre                                                            */
    /* ---------------------------------------------------------------- */

    public function finalize(Manifest $manifest): Manifest
    {
        $this->assertTransition($manifest, 'finalizar');
        if ($manifest->roadside_assistance_pending) {
            throw new ManifestException('ASISTENCIA_PENDIENTE', 'Hay una asistencia vial pendiente de resolver');
        }
        $pending = $manifest->details()->where('status', ManifestDetail::STATUS_PENDIENTE)->count();
        if ($pending > 0) {
            throw new ManifestException('PEDIDOS_PENDIENTES', "Quedan {$pending} paradas sin resolver");
        }

        $conIncidencias = $manifest->details()
            ->where('status', ManifestDetail::STATUS_NO_ENTREGADO)
            ->exists();

        $manifest->update([
            'status' => $conIncidencias
                ? Manifest::STATUS_COMPLETADO_CON_INCIDENCIAS
                : Manifest::STATUS_COMPLETADO,
            'closed_at' => now(),
        ]);

        return $manifest->fresh();
    }

    /* ---------------------------------------------------------------- */
    /* Traspaso                                                          */
    /* ---------------------------------------------------------------- */

    /**
     * Chofer solicita traspaso. detailIds vacío = todos los pendientes;
     * subconjunto = traspaso parcial.
     */
    public function requestTransfer(Manifest $manifest, string $reason, ?array $detailIds, User $requester): ManifestTransferRequest
    {
        $this->assertTransition($manifest, 'solicitarTraspaso');
        if (! $reason) {
            throw new ManifestException('SIN_MOTIVO', 'La solicitud requiere el motivo de la incidencia');
        }

        $pendingIds = $manifest->details()
            ->where('status', ManifestDetail::STATUS_PENDIENTE)
            ->pluck('id');
        $ids = $detailIds ? array_values($detailIds) : $pendingIds->all();

        if (! count($ids)) {
            throw new ManifestException('SIN_ITEMS', 'No hay paradas pendientes para trasladar');
        }
        if (count(array_diff($ids, $pendingIds->all()))) {
            throw new ManifestException('ITEMS_INVALIDOS', 'Solo pueden trasladarse paradas pendientes');
        }

        return DB::transaction(function () use ($manifest, $reason, $ids, $requester) {
            $solicitud = $manifest->transferRequests()->create([
                'reason' => $reason,
                'status' => ManifestTransferRequest::STATUS_PENDIENTE,
                'requested_by' => $requester->id,
                'detail_ids' => $ids,
            ]);
            $manifest->update(['status' => Manifest::STATUS_EN_SOLICITUD_TRASPASO]);

            return $solicitud;
        });
    }

    /**
     * Operaciones aprueba: congela entregados, los seleccionados pasan a
     * TRANSFERIDO, los pendientes no seleccionados quedan NO_ENTREGADO,
     * el original queda TRANSFERIDO y se genera el derivado ASIGNADO
     * al chofer de reemplazo.
     *
     * @return array{original: Manifest, derivado: Manifest}
     */
    public function approveTransfer(
        Manifest $manifest,
        ManifestTransferRequest $request,
        User $reviewer,
        int $replacementDriverId,
        ?int $replacementVehicleId = null,
        string $pendingReason = 'NO_TRANSFERIDO'
    ): array {
        $this->assertTransition($manifest, 'aprobarTraspaso');
        $this->assertPendingRequest($manifest, $request);

        return DB::transaction(function () use ($manifest, $request, $reviewer, $replacementDriverId, $replacementVehicleId, $pendingReason) {
            $selected = collect($request->detail_ids);

            foreach ($manifest->details as $detail) {
                if ($detail->status !== ManifestDetail::STATUS_PENDIENTE) {
                    continue; // entregados congelados, fallos conservados
                }
                $detail->update($selected->contains($detail->id)
                    ? ['status' => ManifestDetail::STATUS_TRANSFERIDO]
                    : ['status' => ManifestDetail::STATUS_NO_ENTREGADO,
                       'failure_reason' => $pendingReason,
                       'visited_at' => now()]);
            }

            $request->update([
                'status' => ManifestTransferRequest::STATUS_APROBADA,
                'reviewed_by' => $reviewer->id,
                'resolved_at' => now(),
            ]);

            $derivados = $manifest->transferRequests()
                ->where('status', ManifestTransferRequest::STATUS_APROBADA)
                ->count();
            $derivado = $this->create(
                $manifest->details()
                    ->where('status', ManifestDetail::STATUS_TRANSFERIDO)
                    ->orderBy('sequence')
                    ->get()
                    ->map(fn (ManifestDetail $d) => [
                        'tracking_code' => $d->tracking_code,
                        'shipment_id' => $d->shipment_id,
                        'origin_detail_id' => $d->id,
                    ])->all(),
                $reviewer,
                $manifest->id,
                "{$manifest->code}-D{$derivados}"
            );
            $derivado->update([
                'status' => Manifest::STATUS_ASIGNADO,
                'driver_id' => $replacementDriverId,
                'vehicle_id' => $replacementVehicleId,
            ]);

            $manifest->update([
                'status' => Manifest::STATUS_TRANSFERIDO,
                'closed_at' => now(),
            ]);

            return ['original' => $manifest->fresh(), 'derivado' => $derivado->fresh('details')];
        });
    }

    /**
     * Rechazo: el manifiesto vuelve a EN_RUTA y queda marcado
     * roadside_assistance_pending (el chofer aguarda remolque/asistencia).
     */
    public function rejectTransfer(
        Manifest $manifest,
        ManifestTransferRequest $request,
        User $reviewer,
        ?string $reason = null
    ): Manifest {
        $this->assertTransition($manifest, 'rechazarTraspaso');
        $this->assertPendingRequest($manifest, $request);

        return DB::transaction(function () use ($manifest, $request, $reviewer, $reason) {
            $request->update([
                'status' => ManifestTransferRequest::STATUS_RECHAZADA,
                'reviewed_by' => $reviewer->id,
                'resolution_reason' => $reason,
                'resolved_at' => now(),
            ]);
            $manifest->update([
                'status' => Manifest::STATUS_EN_RUTA,
                'roadside_assistance_pending' => true,
            ]);

            return $manifest->fresh();
        });
    }

    /** La asistencia llegó: libera el bloqueo y permite seguir o re-solicitar. */
    public function resolveAssistance(Manifest $manifest): Manifest
    {
        $this->assertEnRuta($manifest);
        $manifest->update(['roadside_assistance_pending' => false]);

        return $manifest->fresh();
    }

    private function assertPendingRequest(Manifest $manifest, ManifestTransferRequest $request): void
    {
        if ($request->manifest_id !== $manifest->id) {
            throw new ManifestException('SOLICITUD_NO_EXISTE', 'Solicitud de traspaso no encontrada');
        }
        if ($request->status !== ManifestTransferRequest::STATUS_PENDIENTE) {
            throw new ManifestException('SOLICITUD_RESUELTA', 'La solicitud ya fue resuelta');
        }
    }

    /* ---------------------------------------------------------------- */
    /* Handover físico — doble escaneo en el punto de avería             */
    /* ---------------------------------------------------------------- */

    /**
     * Registra scan-out (chofer origen) o scan-in (reemplazo) de un bulto
     * del manifiesto derivado.
     */
    public function handoverScan(Manifest $derived, string $code, string $phase, ?User $scanner, ?float $lat = null, ?float $lng = null): array
    {
        if ($derived->handover_closed_at) {
            throw new ManifestException('HANDOVER_CERRADO', 'El traspaso físico ya fue cerrado');
        }
        if (! in_array($phase, [ManifestHandoverScan::PHASE_OUT, ManifestHandoverScan::PHASE_IN], true)) {
            throw new ManifestException('FASE_INVALIDA', 'La fase debe ser out o in');
        }

        $derived->handoverScans()->create([
            'tracking_code' => trim($code),
            'phase' => $phase,
            'scanned_by' => $scanner?->id,
            'lat' => $lat,
            'lng' => $lng,
            'scanned_at' => now(),
        ]);

        return $this->handoverStatus($derived->fresh('handoverScans', 'details'));
    }

    /**
     * Resumen de custodia: esperados, entregados (out+in), pendientes y
     * discrepancias (SIN_SCAN_OUT, PAQUETE_NO_ESPERADO).
     */
    public function handoverStatus(Manifest $derived): array
    {
        $expected = $derived->details->pluck('tracking_code');
        $scans = $derived->handoverScans;

        $outs = $scans->where('phase', ManifestHandoverScan::PHASE_OUT)->groupBy('tracking_code');
        $ins = $scans->where('phase', ManifestHandoverScan::PHASE_IN)->groupBy('tracking_code');

        $discrepancias = [];
        foreach ($ins as $code => $_) {
            if ($expected->contains($code) && ! $outs->has($code)) {
                $discrepancias[] = ['codigo' => $code, 'motivo' => 'SIN_SCAN_OUT'];
            }
        }
        foreach ($scans->pluck('tracking_code')->unique() as $code) {
            if (! $expected->contains($code)) {
                $discrepancias[] = ['codigo' => $code, 'motivo' => 'PAQUETE_NO_ESPERADO'];
            }
        }

        $pendientes = $expected->filter(fn ($c) => ! $ins->has($c))->values();
        $entregados = $expected->filter(fn ($c) => $outs->has($c) && $ins->has($c))->values();
        $discrepancias = collect($discrepancias)->unique('codigo')->values();

        return [
            'esperados' => $expected->count(),
            'entregados' => $entregados->count(),
            'pendientes' => $pendientes->all(),
            'discrepancias' => $discrepancias->all(),
            'completo' => $expected->count() > 0 && $pendientes->isEmpty() && $discrepancias->isEmpty(),
            'cerrado' => (bool) $derived->handover_closed_at,
        ];
    }

    /** Cierra el handover; con discrepancias queda marcado en el manifiesto. */
    public function closeHandover(Manifest $derived): array
    {
        $status = $this->handoverStatus($derived->fresh('handoverScans', 'details'));
        $derived->update([
            'handover_closed_at' => now(),
            'handover_discrepancies' => ! $status['completo'],
        ]);
        $status['cerrado'] = true;

        return $status;
    }
}
