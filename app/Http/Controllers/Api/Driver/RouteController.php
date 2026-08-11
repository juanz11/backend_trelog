<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Models\DeliveryRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RouteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $routes = DeliveryRoute::where('driver_id', $request->user()->id)
            ->withCount('stops')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($routes);
    }

    public function show(Request $request, DeliveryRoute $route): JsonResponse
    {
        abort_if($route->driver_id !== $request->user()->id, 403);

        return response()->json(
            $route->load(['stops', 'auditLogs'])
        );
    }
}
