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
        $incidents = Incident::with('driver')->orderByDesc('created_at')->get();

        return response()->json($incidents->map(fn ($incident) => $this->mapIncident($incident)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ship' => 'nullable|string|max:255',
            'title' => 'required|string|max:255',
            'category' => 'nullable|string|max:255',
            'severity' => 'nullable|string|in:low,medium,high,critical',
            'description' => 'nullable|string',
        ]);

        $data['severity'] = $data['severity'] ?? 'medium';
        $data['status'] = 'open';
        $data['driver_id'] = $request->user()->id;
        $data['code'] = 'INC-' . strtoupper(Str::random(6));

        $incident = Incident::create($data);

        return response()->json($this->mapIncident($incident), 201);
    }

    public function updateStatus(Request $request, Incident $incident): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|in:open,investigating,resolved,closed',
        ]);

        $incident->update(['status' => $data['status']]);

        return response()->json($this->mapIncident($incident));
    }

    private function mapIncident(Incident $incident): array
    {
        $created = $incident->created_at ? Carbon::parse($incident->created_at) : null;
        $sev = strtolower($incident->severity);
        $st = strtolower($incident->status);
        $cost = in_array($sev, ['high', 'critical'], true)
            || Str::contains(strtolower($incident->category ?? ''), ['damage', 'loss', 'theft', 'broken']);

        return [
            'id' => $incident->code ?? ('INC-' . $incident->id),
            'numericId' => $incident->id,
            'ship' => $incident->ship ?? '—',
            'type' => $incident->title ?? $incident->category ?? '—',
            'category' => $incident->category,
            'description' => $incident->description,
            'sev' => $sev,
            'when' => $created ? $created->format('d M · H:i') : '—',
            'created_at' => $created?->toIso8601String(),
            'owner' => $incident->driver?->name ?? '—',
            'st' => $st,
            'cost' => $cost,
            'photo_url' => $incident->photo_url,
        ];
    }
}
