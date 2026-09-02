<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class IncidentAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (!$request->user()->hasAnyRole(['admin', 'operations']) && !$request->user()->hasPermission('dispatch.manage')) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $incidents = Incident::with('driver')->orderByDesc('created_at')->get();

        $mapped = $incidents->map(function ($incident) {
            $created = $incident->created_at ? Carbon::parse($incident->created_at) : null;
            $sev = strtolower($incident->severity);
            $st = strtolower($incident->status);
            $cost = in_array($sev, ['high', 'critical'], true)
                || Str::contains(strtolower($incident->category ?? ''), ['damage', 'loss', 'theft', 'broken']);

            return [
                'id' => $incident->code ?? ('INC-' . $incident->id),
                'ship' => $incident->ship ?? '—',
                'type' => $incident->title ?? $incident->category ?? '—',
                'sev' => $sev,
                'when' => $created ? $created->format('d M · H:i') : '—',
                'owner' => $incident->driver?->name ?? '—',
                'st' => $st,
                'cost' => $cost,
            ];
        });

        return response()->json($mapped);
    }
}
