<?php

namespace App\Http\Controllers;

use App\Models\ManifestTransferRequest;
use App\Services\ManifestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransferRequestController extends Controller
{
    public function __construct(private readonly ManifestService $service) {}

    /** Mesa de tráfico: cola de solicitudes de traspaso. */
    public function index(Request $request): JsonResponse
    {
        $query = ManifestTransferRequest::with(['manifest.details', 'requester'])
            ->orderByDesc('created_at');
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json(
            $query->get()->map(fn (ManifestTransferRequest $s) => [
                'id' => $s->id,
                'manifiestoId' => $s->manifest_id,
                'manifiestoCodigo' => $s->manifest->code,
                'choferId' => $s->manifest->driver_id,
                'fechaSolicitud' => $s->created_at?->toISOString(),
                'motivoIncidencia' => $s->reason,
                'estado' => $s->status,
                'solicitadoPor' => $s->requested_by,
                'revisadoPor' => $s->reviewed_by,
                'fechaResolucion' => $s->resolved_at?->toISOString(),
                'motivoResolucion' => $s->resolution_reason,
                'items' => $s->detail_ids,
                'itemsDetalle' => $s->manifest->details
                    ->whereIn('id', $s->detail_ids ?? [])
                    ->map(fn ($d) => [
                        'id' => $d->id,
                        'codigoSeguimiento' => $d->tracking_code,
                        'secuenciaVisita' => $d->sequence,
                    ])->values(),
            ])
        );
    }

    /**
     * Aprobar: genera manifiesto derivado asignado al reemplazo.
     * Rechazar: el manifiesto vuelve a EN_RUTA aguardando asistencia.
     */
    public function resolve(Request $request, ManifestTransferRequest $transferRequest): JsonResponse
    {
        $data = $request->validate([
            'approve' => ['required', 'boolean'],
            'replacement_driver_id' => ['required_if:approve,true', 'integer', 'exists:users,id'],
            'replacement_vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'reject_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $manifest = $transferRequest->manifest;

        if ($data['approve']) {
            $result = $this->service->approveTransfer(
                $manifest,
                $transferRequest,
                $request->user(),
                (int) $data['replacement_driver_id'],
                $data['replacement_vehicle_id'] ?? null
            );

            return response()->json([
                'original' => $result['original']->toApi(),
                'derivado' => $result['derivado']->toApi(),
            ]);
        }

        $updated = $this->service->rejectTransfer(
            $manifest,
            $transferRequest,
            $request->user(),
            $data['reject_reason'] ?? null
        );

        return response()->json($updated->toApi());
    }
}
