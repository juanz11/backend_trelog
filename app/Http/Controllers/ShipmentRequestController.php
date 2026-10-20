<?php

namespace App\Http\Controllers;

use App\Models\DriverAlert;
use App\Models\ShipmentRequest;
use App\Models\User;
use App\Services\ShipmentDispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShipmentRequestController extends Controller
{
    public function __construct(private ShipmentDispatchService $dispatch)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = ShipmentRequest::with(['shipment.client:id,name,email', 'driver:id,name,email', 'client:id,name,email', 'reviewer:id,name'])
            ->orderByDesc('created_at');

        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        if ($request->has('driver_id')) {
            $query->where('driver_id', $request->get('driver_id'));
        }

        return response()->json($query->get());
    }

    public function pendingCount(Request $request): JsonResponse
    {
        return response()->json([
            'count' => ShipmentRequest::where('status', 'pending')->count(),
            'same_day' => ShipmentRequest::where('status', 'pending')->where('type', 'same_day')->count(),
            'driver' => ShipmentRequest::where('status', 'pending')->where('type', 'driver')->count(),
        ]);
    }

    public function updateStatus(Request $request, ShipmentRequest $shipmentRequest): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:pending,approved,rejected'],
            'review_notes' => ['nullable', 'string', 'max:500'],
            'driver_id' => ['nullable', 'string'],
        ]);

        $newStatus = $data['status'];
        $oldStatus = $shipmentRequest->status;
        $reviewer = $request->user();
        $isSameDay = ($shipmentRequest->type ?? 'driver') === 'same_day';

        // Same-day priority requests come from the CLIENT, so approving one
        // assigns the driver that operations picks right here — the shipment
        // starts moving immediately and the client gets notified.
        if ($newStatus === 'approved' && $isSameDay) {
            if (empty($data['driver_id'])) {
                return response()->json([
                    'message' => 'Selecciona un conductor para asignar el envío de mismo día.',
                ], 422);
            }

            $driver = User::whereHas('roles', fn ($query) => $query->where('name', 'driver'))
                ->where(function ($query) use ($data) {
                    $query->where('id', $data['driver_id'])
                        ->orWhereHas('driverProfile', fn ($profile) => $profile->where('driver_id', $data['driver_id']));
                })
                ->first();

            if (! $driver) {
                return response()->json([
                    'message' => 'El conductor seleccionado no existe o no tiene el rol driver.',
                ], 422);
            }

            $shipment = $this->dispatch->assignDriver($shipmentRequest->shipment, $driver);

            $shipmentRequest->update([
                'status' => 'approved',
                'driver_id' => $driver->id,
                'review_notes' => $data['review_notes'] ?? null,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ]);

            $this->notifySameDayClient($shipmentRequest, 'approved', $data['review_notes'] ?? null, $driver);

            return response()->json([
                'message' => "Envío de mismo día priorizado, asignado a {$driver->name} y cliente notificado.",
                'request' => $shipmentRequest->fresh(),
                'shipment' => $shipment,
            ]);
        }

        if ($newStatus === 'approved' && ! $isSameDay) {
            if ($shipmentRequest->shipment->driver_id !== null
                && $shipmentRequest->shipment->driver_id !== $shipmentRequest->driver_id) {
                return response()->json([
                    'message' => 'El envío ya está asignado a otro conductor.',
                ], 409);
            }

            $shipment = $this->dispatch->assignDriver(
                $shipmentRequest->shipment,
                $shipmentRequest->driver
            );

            $shipmentRequest->update([
                'status' => 'approved',
                'review_notes' => $data['review_notes'] ?? null,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ]);

            return response()->json([
                'message' => 'Solicitud aprobada y envío asignado al conductor.',
                'request' => $shipmentRequest->fresh(),
                'shipment' => $shipment,
            ]);
        }

        // rejected (or setting back to pending)
        if ($newStatus === 'rejected' && $oldStatus === 'approved') {
            // Unassign if it was previously approved by this request
            if ($shipmentRequest->shipment->driver_id === $shipmentRequest->driver_id) {
                $this->dispatch->assignDriver($shipmentRequest->shipment, null);
            }
        }

        $shipmentRequest->update([
            'status' => $newStatus,
            'review_notes' => $data['review_notes'] ?? null,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        if ($isSameDay && $newStatus !== 'pending') {
            $this->notifySameDayClient($shipmentRequest, $newStatus, $data['review_notes'] ?? null);
        }

        return response()->json([
            'message' => $isSameDay && $newStatus === 'approved'
                ? 'Envío de mismo día priorizado y cliente notificado.'
                : 'Estado de la solicitud actualizado.',
            'request' => $shipmentRequest->fresh(),
        ]);
    }

    /**
     * Notify the client about the resolution of their same-day priority
     * request (reuses driver_alerts, keyed by user id).
     */
    private function notifySameDayClient(ShipmentRequest $shipmentRequest, string $status, ?string $notes = null, ?User $driver = null): void
    {
        if (! $shipmentRequest->client_id) {
            return;
        }

        $tracking = $shipmentRequest->shipment->tracking_number ?? 'ENV-'.$shipmentRequest->shipment_id;
        $approved = $status === 'approved';

        DriverAlert::create([
            'driver_id' => $shipmentRequest->client_id,
            'title' => $approved ? 'Envío de mismo día priorizado' : 'Solicitud de mismo día no atendida',
            'body' => ($approved
                ? "Tu envío {$tracking} fue marcado como prioritario para entrega el mismo día."
                : "Tu envío {$tracking} no pudo ser atendido como entrega de mismo día.")
                .($driver ? " Conductor asignado: {$driver->name}." : '')
                .($notes ? " Nota: {$notes}" : ''),
        ]);
    }
}
