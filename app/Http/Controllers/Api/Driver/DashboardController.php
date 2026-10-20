<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Models\DeliveryRoute;
use App\Models\Shipment;
use App\Services\ShipmentDispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request, ShipmentDispatchService $dispatch): JsonResponse
    {
        $driver = $request->user();
        $openStatuses = ['In progress', 'Pending'];

        // Heal drift: an assigned shipment that is still open must live on an
        // open route — otherwise it would never show up in the route progress.
        $orphans = Shipment::where('driver_id', $driver->id)
            ->whereNotIn('status', ShipmentDispatchService::CLOSED_STATUSES)
            ->with('stop.route')
            ->get()
            ->filter(function ($shipment) use ($openStatuses) {
                $route = $shipment->stop?->route;
                return ! $route || ! in_array($route->status, $openStatuses, true);
            });

        foreach ($orphans as $shipment) {
            $dispatch->syncStop($shipment, $driver);
        }

        $activeRoute = DeliveryRoute::where('driver_id', $driver->id)
            ->whereIn('status', $openStatuses)
            ->with('stops')
            ->orderByRaw('FIELD(status, "In progress", "Pending")')
            ->latest()
            ->first();

        $stops = $activeRoute?->stops ?? collect();
        $completed = $stops->where('state', 'Done')->count();
        $delayed = $stops->where('state', 'Delayed')->count();
        $pendingDeliveries = $stops->where('type', 'Delivery')->whereNotIn('state', ['Done'])->count();
        $pendingPickups = $stops->where('type', 'Pickup')->whereNotIn('state', ['Done'])->count();
        $nextStop = $stops->firstWhere('state', 'Next') ?? $stops->firstWhere('state', 'Pending');

        return response()->json([
            'driver' => $driver->load('driverProfile'),
            'active_route' => $activeRoute,
            'kpis' => [
                'pending_pickups' => $pendingPickups,
                'pending_deliveries' => $pendingDeliveries,
                'completed_stops' => $completed,
                'delayed_stops' => $delayed,
            ],
            'next_stop' => $nextStop,
            'alerts' => $driver->alerts()->latest()->get(),
        ]);
    }
}
