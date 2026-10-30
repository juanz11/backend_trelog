<?php

namespace App\Http\Controllers;

use App\Models\Manifest;
use App\Services\ManifestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManifestController extends Controller
{
    public function __construct(private readonly ManifestService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = Manifest::with(['details', 'transferRequests'])->orderByDesc('created_at');
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json(
            $query->get()->map(fn (Manifest $m) => $m->toApi())
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'codigo' => ['nullable', 'string', 'max:100'],
            'stops' => ['required', 'array', 'min:1'],
            'stops.*.tracking_code' => ['required', 'string', 'max:100'],
            'stops.*.shipment_id' => ['nullable', 'integer', 'exists:shipments,id'],
            'origin_manifest_id' => ['nullable', 'integer', 'exists:manifests,id'],
        ]);

        $manifest = $this->service->create(
            $data['stops'],
            $request->user(),
            $data['origin_manifest_id'] ?? null,
            $data['codigo'] ?? null
        );

        return response()->json($manifest->toApi(), 201);
    }

    public function show(Manifest $manifest): JsonResponse
    {
        return response()->json($manifest->toApi());
    }

    /** Asignar chofer + vehículo (CREADO → ASIGNADO). */
    public function assign(Request $request, Manifest $manifest): JsonResponse
    {
        $data = $request->validate([
            'driver_id' => ['required', 'integer', 'exists:users,id'],
            'vehicle_id' => ['required', 'integer', 'exists:vehicles,id'],
        ]);

        $updated = $this->service->assign($manifest, $data['driver_id'], $data['vehicle_id']);

        return response()->json($updated->toApi());
    }

    /** Listado de ajustes/diferencias para operations y finanzas. */
    public function adjustments(Request $request): JsonResponse
    {
        $rows = \App\Models\ManifestAdjustment::with('manifest')->latest()->limit(300)->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'codigo' => $a->tracking_code,
                'manifiesto' => $a->manifest?->code,
                'tipo' => $a->type,
                'motivo' => $a->reason,
                'esperado' => $a->expected_value,
                'real' => $a->actual_value,
                'estado' => $a->status,
                'fecha' => $a->created_at?->toISOString(),
            ]);

        return response()->json($rows);
    }

    /** Reemplaza las paradas mientras el manifiesto esté editable. */
    public function updateStops(Request $request, Manifest $manifest): JsonResponse
    {
        $data = $request->validate([
            'stops' => ['required', 'array', 'min:1'],
            'stops.*.tracking_code' => ['required', 'string', 'max:100'],
            'stops.*.shipment_id' => ['nullable', 'integer', 'exists:shipments,id'],
        ]);

        $updated = $this->service->updateStops($manifest, $data['stops']);

        return response()->json($updated->toApi());
    }
}
