<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Models\DeliveryRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $driver = $request->user();

        $activeRoute = DeliveryRoute::where('driver_id', $driver->id)
            ->where('status', 'In progress')
            ->with('stops')
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
