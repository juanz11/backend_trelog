<?php

namespace App\Services;

use App\Exceptions\ManifestException;
use App\Models\Manifest;
use App\Models\ManifestAdjustment;
use App\Models\ManifestDetail;
use App\Models\ManifestHubScan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * DCU-08 Hub y almacén: recepción contra manifiesto, escaneo de
 * entrada/salida por paquete, validación de diferencias que bloquea el
 * despacho y ajustes de inventario aprobados por supervisor (DRV §6.1).
 * Cada cambio de custodio requiere escaneo de salida, de entrada y
 * conciliación.
 */
class HubManifestService
{
    private const ESTADOS_FINALES = [
        Manifest::STATUS_COMPLETADO,
        Manifest::STATUS_COMPLETADO_CON_INCIDENCIAS,
        Manifest::STATUS_TRANSFERIDO,
    ];

    /* ---------------------------------------------------------------- */
    /* Escaneo                                                           */
    /* ---------------------------------------------------------------- */

    /**
     * Registra el escaneo de entrada (in) o salida (out) de un paquete
     * contra el manifiesto. Los códigos que no figuran se registran igual
     * y aparecen como discrepancia en la conciliación.
     */
    public function scan(Manifest $manifest, string $code, string $phase, ?User $scanner, array $meta = []): ManifestHubScan
    {
        $this->assertNotFinal($manifest);
        $phases = [
            ManifestHubScan::PHASE_IN,
            ManifestHubScan::PHASE_OUT,
            ManifestHubScan::PHASE_MOVE,
            ManifestHubScan::PHASE_INSPECT,
        ];
        if (! in_array($phase, $phases, true)) {
            throw new ManifestException('FASE_INVALIDA', 'La fase debe ser in, out, move o inspect');
        }
        if ($manifest->hub_dispatched_at) {
            throw new ManifestException('HUB_DESPACHADO', 'El manifiesto ya fue despachado del hub');
        }

        $code = trim($code);
        $detail = $manifest->details()->where('tracking_code', $code)->first();

        $location = isset($meta['location']) ? trim((string) $meta['location']) : null;
        $condition = $meta['condition'] ?? null;

        // Movimiento interno: requiere ubicación y la deja grabada en el detalle.
        if ($phase === ManifestHubScan::PHASE_MOVE) {
            if (! $location) {
                throw new ManifestException('SIN_UBICACION', 'El movimiento interno requiere la ubicación destino');
            }
            $detail?->update(['location' => $location]);
        }

        // Inspección: requiere condición; dañado/abierto abre ajuste pendiente
        // de aprobación del supervisor (bloquea el despacho, DCU-08).
        if ($phase === ManifestHubScan::PHASE_INSPECT) {
            if (! in_array($condition, ['ok', 'damaged', 'opened'], true)) {
                throw new ManifestException('CONDICION_INVALIDA', 'La inspección requiere condición: ok, damaged u opened');
            }
            if ($condition !== 'ok') {
                $this->storeAdjustment(
                    $manifest,
                    $code,
                    $condition === 'damaged' ? ManifestAdjustment::TYPE_AVERIADO : ManifestAdjustment::TYPE_OTRO,
                    $meta['notes'] ?: "Inspección: mercancía {$condition}",
                    $scanner,
                    $meta
                );
            }
        }

        $scan = $manifest->hubScans()->create([
            'manifest_detail_id' => $detail?->id,
            'tracking_code' => $code,
            'phase' => $phase,
            'location' => $location,
            'condition' => $condition,
            'notes' => $meta['notes'] ?? null,
            'evidence_path' => $meta['evidence_path'] ?? null,
            'scanned_by' => $scanner?->id,
            'scanned_at' => now(),
        ]);

        // La primera entrada también marca la recepción del manifiesto:
        // escanear paquetes ES recibir — no exige un clic separado.
        if ($phase === ManifestHubScan::PHASE_IN && ! $manifest->hub_received_at) {
            $manifest->update(['hub_received_at' => $scan->scanned_at]);
        }

        return $scan;
    }

    /* ---------------------------------------------------------------- */
    /* Conciliación                                                      */
    /* ---------------------------------------------------------------- */

    /**
     * Esperados (detalles no transferidos), escaneos in/out, diferencias y
     * ajustes. `bloqueado` = hay diferencias sin ajuste aprobado o ajustes
     * pendientes → el despacho no puede ejecutarse (DCU-08).
     */
    public function reconciliation(Manifest $manifest): array
    {
        $manifest->loadMissing(['details', 'hubScans', 'adjustments']);

        $esperados = $manifest->details
            ->where('status', '!=', ManifestDetail::STATUS_TRANSFERIDO)
            ->pluck('tracking_code')
            ->values();

        $scans = $manifest->hubScans;
        $entradas = $scans->where('phase', ManifestHubScan::PHASE_IN)->pluck('tracking_code')->unique()->values();
        $salidas = $scans->where('phase', ManifestHubScan::PHASE_OUT)->pluck('tracking_code')->unique()->values();

        $faltantesEntrada = $esperados->diff($entradas)->values();
        $noEsperados = $entradas->merge($salidas)->unique()->diff($esperados)->values();
        $pendientesSalida = $entradas->intersect($esperados)->diff($salidas)->values();

        $ajustes = $manifest->adjustments;
        $cubiertos = $ajustes->where('status', ManifestAdjustment::STATUS_APROBADO)
            ->pluck('tracking_code');
        $pendientesAjuste = $ajustes->where('status', ManifestAdjustment::STATUS_PENDIENTE);

        $diferencias = $faltantesEntrada->merge($noEsperados)->merge($pendientesSalida)
            ->unique()
            ->values();

        return [
            'esperados' => $esperados->all(),
            'entradas' => $entradas->all(),
            'salidas' => $salidas->all(),
            'faltantesEntrada' => $faltantesEntrada->all(),
            'noEsperados' => $noEsperados->all(),
            'pendientesSalida' => $pendientesSalida->all(),
            'ajustes' => $ajustes->loadMissing(['registrar', 'resolver'])->map(fn (ManifestAdjustment $a) => [
                'id' => $a->id,
                'codigoSeguimiento' => $a->tracking_code,
                'tipo' => $a->type,
                'motivo' => $a->reason,
                'estado' => $a->status,
                'registradoPor' => $a->registered_by,
                'registradoPorNombre' => $a->registrar?->name,
                'resueltoPor' => $a->resolved_by,
                'resueltoPorNombre' => $a->resolver?->name,
                'motivoResolucion' => $a->resolution_reason,
                'fechaResolucion' => $a->resolved_at?->toISOString(),
            ])->values(),
            'recibidoAt' => $manifest->hub_received_at?->toISOString(),
            'despachadoAt' => $manifest->hub_dispatched_at?->toISOString(),
            'bloqueado' => $pendientesAjuste->isNotEmpty()
                || $diferencias->diff($cubiertos)->isNotEmpty(),
        ];
    }

    /** Marca la recepción en hub (puede quedar con diferencias anexadas). */
    public function receive(Manifest $manifest): Manifest
    {
        $this->assertNotFinal($manifest);
        $manifest->update(['hub_received_at' => now()]);

        return $manifest->fresh();
    }

    /**
     * Despacho con escaneo de salida: bloqueado si quedan diferencias sin
     * ajuste aprobado o ajustes pendientes de resolución.
     */
    public function dispatch(Manifest $manifest): Manifest
    {
        $this->assertNotFinal($manifest);
        if (! $manifest->hub_received_at) {
            throw new ManifestException('HUB_NO_RECIBIDO', 'Primero registra la recepción del manifiesto en hub');
        }
        if ($manifest->hub_dispatched_at) {
            throw new ManifestException('HUB_DESPACHADO', 'El manifiesto ya fue despachado del hub');
        }

        $rec = $this->reconciliation($manifest);
        if ($rec['bloqueado']) {
            throw new ManifestException(
                'DESPACHO_BLOQUEADO',
                'El despacho está bloqueado: hay diferencias sin ajuste aprobado'
            );
        }

        $manifest->update(['hub_dispatched_at' => now()]);

        return $manifest->fresh();
    }

    /* ---------------------------------------------------------------- */
    /* Ajustes de inventario (solo anexados; no modifican el manifiesto) */
    /* ---------------------------------------------------------------- */

    public function storeAdjustment(Manifest $manifest, string $code, string $type, string $reason, ?User $user, array $meta = []): ManifestAdjustment
    {
        $this->assertNotFinal($manifest);
        if ($manifest->hub_dispatched_at) {
            throw new ManifestException('HUB_DESPACHADO', 'El manifiesto ya fue despachado del hub');
        }
        if (! in_array($type, [
            ManifestAdjustment::TYPE_FALTANTE,
            ManifestAdjustment::TYPE_SOBRANTE,
            ManifestAdjustment::TYPE_AVERIADO,
            ManifestAdjustment::TYPE_OTRO,
        ], true)) {
            throw new ManifestException('TIPO_INVALIDO', 'Tipo de ajuste inválido');
        }
        if (! $reason) {
            throw new ManifestException('SIN_MOTIVO', 'El ajuste requiere el motivo');
        }

        return $manifest->adjustments()->create([
            'tracking_code' => trim($code),
            'type' => $type,
            'reason' => $reason,
            'status' => ManifestAdjustment::STATUS_PENDIENTE,
            'evidence_path' => $meta['evidence_path'] ?? null,
            'registered_by' => $user?->id,
        ]);
    }

    /** Supervisor aprueba/rechaza el ajuste (desbloquea el despacho). */
    public function resolveAdjustment(ManifestAdjustment $adjustment, bool $approve, ?string $reason, User $reviewer): ManifestAdjustment
    {
        if ($adjustment->status !== ManifestAdjustment::STATUS_PENDIENTE) {
            throw new ManifestException('AJUSTE_RESUELTO', 'El ajuste ya fue resuelto');
        }

        $adjustment->update([
            'status' => $approve
                ? ManifestAdjustment::STATUS_APROBADO
                : ManifestAdjustment::STATUS_RECHAZADO,
            'resolved_by' => $reviewer->id,
            'resolution_reason' => $reason,
            'resolved_at' => now(),
        ]);

        return $adjustment->fresh();
    }

    private function assertNotFinal(Manifest $manifest): void
    {
        if (in_array($manifest->status, self::ESTADOS_FINALES, true)) {
            throw new ManifestException(
                'MANIFIESTO_CERRADO',
                "El manifiesto está en estado {$manifest->status}"
            );
        }
    }
}
