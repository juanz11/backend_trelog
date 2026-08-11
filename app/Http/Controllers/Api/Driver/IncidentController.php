<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class IncidentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $incidents = Incident::where('driver_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json($incidents);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['nullable', 'string', 'max:100'],
            'title' => ['nullable', 'string', 'max:255'],
            'ship' => ['nullable', 'string', 'max:255'],
            'severity' => ['required', 'string', 'in:Low,Medium,High,Critical'],
            'description' => ['nullable', 'string'],
        ]);

        $incident = Incident::create([
            'driver_id' => $request->user()->id,
            'code' => 'INC-'.Str::upper(Str::random(4)).'-'.random_int(100, 999),
            'category' => $data['category'] ?? null,
            'title' => $data['title'] ?? ($data['category'] ?? 'Incidente reportado'),
            'ship' => $data['ship'] ?? null,
            'severity' => $data['severity'],
            'status' => 'Open',
            'description' => $data['description'] ?? null,
        ]);

        return response()->json($incident, 201);
    }
}
