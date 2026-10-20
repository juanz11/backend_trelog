<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Models\RouteAuditLog;
use App\Models\RouteStop;
use App\Services\ShipmentDispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StopController extends Controller
{
    public function __construct(private ShipmentDispatchService $dispatch)
    {
    }

    public function confirm(Request $request, RouteStop $stop): JsonResponse
    {
        $this->authorizeStop($request, $stop);

        $data = $request->validate([
            'received_by' => ['nullable', 'string', 'max:255'],
            'quantity' => ['nullable', 'integer'],
            'condition' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
            'photo' => ['nullable', 'image', 'max:5120'],
            'signature' => ['nullable', 'image', 'max:2048'],
        ]);

        if ($request->hasFile('photo')) {
            $data['photo_path'] = $request->file('photo')->store('pod-photos', 'public');
        }
        if ($request->hasFile('signature')) {
            $data['signature_path'] = $request->file('signature')->store('pod-signatures', 'public');
        }
        unset($data['photo'], $data['signature']);

        $stop->update(array_merge($data, ['state' => 'Done']));
        $this->syncShipmentStatus($stop, $stop->type === 'Pickup' ? 'in_transit' : 'delivered');
        $this->dispatch->refreshRouteProgress($stop->route_id);

        RouteAuditLog::create([
            'route_id' => $stop->route_id,
            'title' => "Stop {$stop->n} completed",
            'meta' => ($data['received_by'] ?? null) ?: $stop->name,
        ]);

        return response()->json($stop->fresh());
    }

    public function fail(Request $request, RouteStop $stop): JsonResponse
    {
        $this->authorizeStop($request, $stop);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        $stop->update(['state' => 'Failed']);
        $this->syncShipmentStatus($stop, 'incident');
        $this->dispatch->refreshRouteProgress($stop->route_id);

        RouteAuditLog::create([
            'route_id' => $stop->route_id,
            'title' => "Stop {$stop->n} failed",
            'meta' => $data['reason'],
        ]);

        return response()->json($stop->fresh());
    }

    private function authorizeStop(Request $request, RouteStop $stop): void
    {
        abort_if($stop->route->driver_id !== $request->user()->id, 403);

        $inProgressRoute = \App\Models\DeliveryRoute::where('driver_id', $request->user()->id)
            ->where('status', 'In progress')
            ->first();

        abort_if(
            $inProgressRoute && $stop->route_id !== $inProgressRoute->id,
            422,
            'Tienes otra ruta en curso. Finalízala antes de trabajar esta parada.'
        );
    }

    /**
     * Keep the client-facing shipment in sync with the stop the driver just resolved.
     */
    private function syncShipmentStatus(RouteStop $stop, string $status): void
    {
        $stop->shipment?->update(['status' => $status]);
    }
}
