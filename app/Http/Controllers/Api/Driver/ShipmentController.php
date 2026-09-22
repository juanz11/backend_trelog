<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Models\ShipmentRequest;
use App\Services\ShipmentDispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShipmentController extends Controller
{
    /**
     * Statuses a driver is allowed to set from the app.
     */
    private const DRIVER_STATUSES = ['in_transit', 'out_for_delivery', 'delivered', 'incident'];

    /**
     * Statuses that take a shipment out of the driver's active workload.
     */
    private const CLOSED_STATUSES = ShipmentDispatchService::CLOSED_STATUSES;

    public function __construct(private ShipmentDispatchService $dispatch)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $driver = $request->user();

        $assigned = Shipment::with('client:id,name,email')
            ->where('driver_id', $driver->id)
            ->orderByRaw('FIELD(status, "delivered") asc')
            ->orderByDesc('created_at')
            ->get();

        $available = Shipment::with('client:id,name,email')
            ->with(['requests' => fn ($query) => $query->where('driver_id', $driver->id)])
            ->whereNull('driver_id')
            ->whereNotIn('status', self::CLOSED_STATUSES)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'assigned' => $assigned->map(fn ($s) => $this->present($s))->values(),
            'available' => $available->map(fn ($s) => $this->present($s))->values(),
        ]);
    }

    public function show(Request $request, Shipment $shipment): JsonResponse
    {
        abort_if(
            $shipment->driver_id !== null && $shipment->driver_id !== $request->user()->id,
            403,
            'Este envío está asignado a otro conductor.'
        );

        return response()->json($this->present($shipment->load('client:id,name,email', 'stop')));
    }

    /**
     * Create a pending request to have operations assign an unassigned
     * shipment to this driver. The driver can no longer self-claim.
     */
    public function request(Request $request, Shipment $shipment): JsonResponse
    {
        $driver = $request->user();

        if ($shipment->driver_id !== null) {
            return response()->json(['message' => 'Este envío ya no está disponible.'], 409);
        }

        if (in_array($shipment->status, self::CLOSED_STATUSES, true)) {
            return response()->json(['message' => 'Este envío ya no está disponible.'], 409);
        }

        $data = $request->validate([
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        $existing = ShipmentRequest::where('shipment_id', $shipment->id)
            ->where('driver_id', $driver->id)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Ya existe una solicitud para este envío.',
                'request' => $this->presentRequest($existing),
            ], 409);
        }

        $shipmentRequest = ShipmentRequest::create([
            'shipment_id' => $shipment->id,
            'driver_id' => $driver->id,
            'status' => 'pending',
            'message' => $data['message'] ?? null,
        ]);

        return response()->json([
            'message' => 'Solicitud enviada a operaciones.',
            'request' => $this->presentRequest($shipmentRequest),
        ], 201);
    }

    public function updateStatus(Request $request, Shipment $shipment): JsonResponse
    {
        $driver = $request->user();

        abort_if($shipment->driver_id !== $driver->id, 403, 'Este envío no está asignado a ti.');

        $data = $request->validate([
            'status' => ['required', 'string', 'in:' . implode(',', self::DRIVER_STATUSES)],
            'notes' => ['nullable', 'string'],
        ]);

        $shipment = $this->dispatch->applyStatus($shipment, $data['status'], $data['notes'] ?? null);

        return response()->json($this->present($shipment));
    }

    private function present(Shipment $shipment): array
    {
        $request = $shipment->requests->first();
        return [
            'id' => $shipment->id,
            'tracking_number' => $shipment->tracking_number,
            'origin' => $shipment->origin,
            'destination' => $shipment->destination,
            'recipient_name' => $shipment->recipient_name,
            'recipient_phone' => $shipment->recipient_phone,
            'service_type' => $shipment->service_type,
            'pieces' => $shipment->pieces,
            'weight' => $shipment->weight,
            'status' => $shipment->status,
            'notes' => $shipment->notes,
            'client_name' => $shipment->client?->name,
            'driver_id' => $shipment->driver_id,
            'assigned_at' => $shipment->assigned_at?->toIso8601String(),
            'created_at' => $shipment->created_at?->toIso8601String(),
            'stop_id' => $shipment->stop?->id,
            'request' => $request ? $this->presentRequest($request) : null,
        ];
    }

    private function presentRequest(ShipmentRequest $shipmentRequest): array
    {
        return [
            'id' => $shipmentRequest->id,
            'shipment_id' => $shipmentRequest->shipment_id,
            'driver_id' => $shipmentRequest->driver_id,
            'status' => $shipmentRequest->status,
            'message' => $shipmentRequest->message,
            'review_notes' => $shipmentRequest->review_notes,
            'reviewed_at' => $shipmentRequest->reviewed_at?->toIso8601String(),
            'created_at' => $shipmentRequest->created_at?->toIso8601String(),
        ];
    }
}
