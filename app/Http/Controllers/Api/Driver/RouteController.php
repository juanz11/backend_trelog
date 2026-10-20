<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Models\DeliveryRoute;
use App\Models\RouteAuditLog;
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

    /**
     * Mark a pending route as the driver's route in progress. Only one
     * route can be underway at a time so the driver doesn't mix routes.
     */
    public function start(Request $request, DeliveryRoute $route): JsonResponse
    {
        abort_if($route->driver_id !== $request->user()->id, 403);

        if ($route->status !== 'Pending') {
            return response()->json(['message' => 'Esta ruta ya fue iniciada.'], 409);
        }

        $inProgress = DeliveryRoute::where('driver_id', $request->user()->id)
            ->where('status', 'In progress')
            ->exists();

        if ($inProgress) {
            return response()->json([
                'message' => 'Ya tienes una ruta en curso. Finalízala antes de iniciar otra.',
            ], 422);
        }

        $route->update(['status' => 'In progress']);

        RouteAuditLog::create([
            'route_id' => $route->id,
            'title' => 'Ruta iniciada por el conductor',
            'meta' => null,
        ]);

        return response()->json($route->fresh()->load('stops'));
    }

    /**
     * Close the route once every stop is resolved, storing the driver's
     * closing report and evidence (photo + signature).
     */
    public function complete(Request $request, DeliveryRoute $route): JsonResponse
    {
        abort_if($route->driver_id !== $request->user()->id, 403);

        if ($route->status === 'Completed') {
            return response()->json(['message' => 'Esta ruta ya está completada.'], 409);
        }

        $unresolvedStops = $route->stops()
            ->whereNotIn('state', ['Done', 'Failed'])
            ->with('shipment')
            ->get();

        $data = $request->validate([
            'received_by' => ['required', 'string', 'max:255'],
            'quantity' => ['nullable', 'integer', 'min:0'],
            'condition' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,heic', 'max:8192'],
            'signature' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:4096'],
        ]);

        if ($request->hasFile('photo')) {
            $route->close_photo_path = $request->file('photo')
                ->store('route-close-photos', 'public');
        }
        if ($request->hasFile('signature')) {
            $route->close_signature_path = $request->file('signature')
                ->store('route-close-signatures', 'public');
        }

        $route->update([
            'status' => 'Completed',
            'progress' => 1.0,
            'completed_at' => now(),
            'close_received_by' => $data['received_by'],
            'close_quantity' => $data['quantity'] ?? null,
            'close_condition' => $data['condition'] ?? null,
            'close_notes' => $data['notes'] ?? null,
        ]);

        // Shipments left undelivered go back to the operations pool so they
        // stop showing up in the driver's pending work and can be reassigned.
        foreach ($unresolvedStops as $stop) {
            $stop->shipment?->update([
                'driver_id' => null,
                'assigned_at' => null,
                'status' => 'pending',
            ]);
        }

        RouteAuditLog::create([
            'route_id' => $route->id,
            'title' => 'Ruta completada por el conductor',
            'meta' => ($data['notes'] ?? '') . ($unresolvedStops->isNotEmpty()
                ? " · {$unresolvedStops->count()} parada(s) sin resolver"
                : ''),
        ]);

        return response()->json($route->fresh()->load('stops'));
    }
}
