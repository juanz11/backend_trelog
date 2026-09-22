<?php

namespace App\Http\Controllers;

use App\Models\ShipmentRequest;
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
        $query = ShipmentRequest::with(['shipment.client:id,name,email', 'driver:id,name,email', 'reviewer:id,name'])
            ->orderByDesc('created_at');

        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        if ($request->has('driver_id')) {
            $query->where('driver_id', $request->get('driver_id'));
        }

        return response()->json($query->get());
    }

    public function updateStatus(Request $request, ShipmentRequest $shipmentRequest): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:pending,approved,rejected'],
            'review_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $newStatus = $data['status'];
        $oldStatus = $shipmentRequest->status;
        $reviewer = $request->user();

        if ($newStatus === 'approved') {
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

        return response()->json([
            'message' => 'Estado de la solicitud actualizado.',
            'request' => $shipmentRequest->fresh(),
        ]);
    }
}
