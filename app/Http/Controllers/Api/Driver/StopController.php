<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Models\RouteAuditLog;
use App\Models\RouteStop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StopController extends Controller
{
    public function confirm(Request $request, RouteStop $stop): JsonResponse
    {
        $this->authorizeStop($request, $stop);

        $data = $request->validate([
            'received_by' => ['nullable', 'string', 'max:255'],
            'quantity' => ['nullable', 'integer'],
            'condition' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
        ]);

        $stop->update(['state' => 'Done']);

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
    }
}
