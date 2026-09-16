<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Models\DeliveryRoute;
use App\Models\RouteAuditLog;
use App\Models\RouteStop;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShipmentController extends Controller
{
    /**
     * Statuses a driver is allowed to set from the app.
     */
    private const DRIVER_STATUSES = ['in_transit', 'out_for_delivery', 'delivered', 'incident'];

    /**
     * Statuses that take a shipment out of the driver's active workload.
     */
    private const CLOSED_STATUSES = ['delivered', 'cancelled'];

    public function index(Request $request): JsonResponse
    {
        $driver = $request->user();

        $assigned = Shipment::with('client:id,name,email')
            ->where('driver_id', $driver->id)
            ->orderByRaw('FIELD(status, "delivered") asc')
            ->orderByDesc('created_at')
            ->get();

        $available = Shipment::with('client:id,name,email')
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
     * Self-assign an unassigned shipment and turn it into a stop on the
     * driver's active route.
     */
    public function claim(Request $request, Shipment $shipment): JsonResponse
    {
        $driver = $request->user();

        if ($shipment->driver_id === $driver->id) {
            return response()->json($this->present($shipment));
        }

        if ($shipment->driver_id !== null) {
            return response()->json(['message' => 'Este envío ya fue tomado por otro conductor.'], 409);
        }

        if (in_array($shipment->status, self::CLOSED_STATUSES, true)) {
            return response()->json(['message' => 'Este envío ya no está disponible.'], 409);
        }

        DB::transaction(function () use ($shipment, $driver) {
            $shipment->update([
                'driver_id' => $driver->id,
                'assigned_at' => now(),
            ]);

            $this->syncStopForShipment($shipment, $driver);
        });

        return response()->json($this->present($shipment->fresh(['stop'])));
    }

    public function updateStatus(Request $request, Shipment $shipment): JsonResponse
    {
        $driver = $request->user();

        abort_if($shipment->driver_id !== $driver->id, 403, 'Este envío no está asignado a ti.');

        $data = $request->validate([
            'status' => ['required', 'string', 'in:' . implode(',', self::DRIVER_STATUSES)],
            'notes' => ['nullable', 'string'],
        ]);

        DB::transaction(function () use ($shipment, $driver, $data) {
            $shipment->update(['status' => $data['status']]);

            $stop = $shipment->stop ?? $this->syncStopForShipment($shipment, $driver);
            $stop->update(['state' => $this->stopStateFor($data['status'])]);

            RouteAuditLog::create([
                'route_id' => $stop->route_id,
                'title' => "Envío {$shipment->tracking_number} · {$data['status']}",
                'meta' => $data['notes'] ?? $shipment->destination,
            ]);

            $this->refreshRouteProgress($stop->route_id);
        });

        return response()->json($this->present($shipment->fresh(['stop'])));
    }

    /**
     * Ensure the shipment is represented as a stop on the driver's active route.
     */
    private function syncStopForShipment(Shipment $shipment, User $driver): RouteStop
    {
        if ($shipment->stop) {
            return $shipment->stop;
        }

        $route = $this->activeRouteFor($driver);

        $stop = RouteStop::create([
            'route_id' => $route->id,
            'shipment_id' => $shipment->id,
            'n' => (int) RouteStop::where('route_id', $route->id)->max('n') + 1,
            'name' => $shipment->recipient_name ?: ($shipment->tracking_number ?: "Envío #{$shipment->id}"),
            'addr' => $shipment->destination ?: '—',
            'type' => 'Delivery',
            'state' => $this->stopStateFor($shipment->status),
        ]);

        $this->refreshRouteProgress($route->id);

        $shipment->setRelation('stop', $stop);

        return $stop;
    }

    private function activeRouteFor(User $driver): DeliveryRoute
    {
        $route = DeliveryRoute::where('driver_id', $driver->id)
            ->whereIn('status', ['In progress', 'Pending'])
            ->orderByRaw('FIELD(status, "In progress", "Pending")')
            ->latest()
            ->first();

        if ($route) {
            return $route;
        }

        return DeliveryRoute::create([
            'driver_id' => $driver->id,
            'code' => 'RT-' . now()->format('dmy') . '-' . strtoupper(substr(md5((string) $driver->id), 0, 2)),
            'date_label' => ucfirst(now()->translatedFormat('l, j \d\e F')),
            'status' => 'Pending',
            'vehicle' => $driver->driverProfile?->vehicle,
        ]);
    }

    private function refreshRouteProgress(int $routeId): void
    {
        $stops = RouteStop::where('route_id', $routeId)->get();
        $done = $stops->where('state', 'Done')->count();

        DeliveryRoute::where('id', $routeId)->update([
            'stops_count' => $stops->count(),
            'progress' => $stops->isEmpty() ? 0 : round($done / $stops->count(), 2),
        ]);
    }

    private function stopStateFor(string $shipmentStatus): string
    {
        return match ($shipmentStatus) {
            'delivered' => 'Done',
            'incident' => 'Failed',
            'in_transit', 'out_for_delivery' => 'Next',
            default => 'Pending',
        };
    }

    private function present(Shipment $shipment): array
    {
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
        ];
    }
}
