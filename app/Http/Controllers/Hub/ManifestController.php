<?php

namespace App\Http\Controllers\Hub;

use App\Exceptions\ManifestException;
use App\Http\Controllers\Controller;
use App\Models\Manifest;
use App\Models\ManifestAdjustment;
use App\Services\HubManifestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * DCU-08 Hub y almacén — operario escanea contra manifiesto, supervisor
 * aprueba ajustes. Permisos: hub.view (lectura), hub.manage (escaneos,
 * recepción, despacho, registro de ajustes), hub.adjustments.approve.
 */
class ManifestController extends Controller
{
    public function __construct(private readonly HubManifestService $service) {}

    public function index(): JsonResponse
    {
        $manifests = Manifest::with(['details', 'transferRequests'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json($manifests->map(fn (Manifest $m) => $m->toApi()));
    }

    /** Detalle del manifiesto + conciliación de hub. */
    public function show(Manifest $manifest): JsonResponse
    {
        return response()->json([
            'manifest' => $manifest->toApi(),
            'conciliacion' => $this->service->reconciliation($manifest),
            'escaneos' => $manifest->hubScans->map(fn ($s) => [
                'id' => $s->id,
                'tracking_code' => $s->tracking_code,
                'fase' => $s->phase,
                'ubicacion' => $s->location,
                'condicion' => $s->condition,
                'scanned_by' => $s->scanned_by,
                'scanned_at' => $s->scanned_at?->toISOString(),
                'notas' => $s->notes,
            ])->values(),
        ]);
    }

    /** Escaneo de entrada (in) o salida (out) de paquete contra manifiesto. */
    public function scan(Request $request, Manifest $manifest): JsonResponse
    {
        $data = $request->validate([
            'codigo' => ['required', 'string', 'max:100'],
            'phase' => ['required', 'in:in,out,move,inspect'],
            'location' => ['nullable', 'string', 'max:100'],
            'condition' => ['nullable', 'in:ok,damaged,opened'],
            'notes' => ['nullable', 'string', 'max:500'],
            'evidence' => ['nullable', 'image', 'max:8192'],
        ]);

        $meta = [
            'notes' => $data['notes'] ?? null,
            'location' => $data['location'] ?? null,
            'condition' => $data['condition'] ?? null,
        ];
        if ($request->hasFile('evidence')) {
            $meta['evidence_path'] = $request->file('evidence')->store('hub-evidence', 'public');
        }

        $scan = $this->service->scan($manifest, $data['codigo'], $data['phase'], $request->user(), $meta);

        return response()->json([
            'scan' => $scan,
            'conciliacion' => $this->service->reconciliation($manifest->fresh()),
        ], 201);
    }

    /** Marca la recepción del manifiesto en el hub. */
    public function receive(Manifest $manifest): JsonResponse
    {
        $manifest = $this->service->receive($manifest);

        return response()->json([
            'manifest' => $manifest->toApi(),
            'conciliacion' => $this->service->reconciliation($manifest),
        ]);
    }

    /** Despacha el manifiesto; bloqueado por diferencias sin ajuste. */
    public function dispatchManifest(Manifest $manifest): JsonResponse
    {
        $manifest = $this->service->dispatch($manifest);

        return response()->json([
            'manifest' => $manifest->toApi(),
            'conciliacion' => $this->service->reconciliation($manifest),
        ]);
    }

    /** Registra un ajuste de inventario (solo anexado). */
    public function storeAdjustment(Request $request, Manifest $manifest): JsonResponse
    {
        $data = $request->validate([
            'codigo' => ['required', 'string', 'max:100'],
            'type' => ['required', 'in:FALTANTE,SOBRANTE,AVERIADO,OTRO'],
            'reason' => ['required', 'string', 'max:500'],
            'evidence' => ['nullable', 'image', 'max:8192'],
        ]);

        $meta = [];
        if ($request->hasFile('evidence')) {
            $meta['evidence_path'] = $request->file('evidence')->store('hub-evidence', 'public');
        }

        $adjustment = $this->service->storeAdjustment(
            $manifest, $data['codigo'], $data['type'], $data['reason'], $request->user(), $meta
        );

        return response()->json($adjustment, 201);
    }

    /** Supervisor aprueba o rechaza un ajuste pendiente. */
    public function resolveAdjustment(Request $request, ManifestAdjustment $adjustment): JsonResponse
    {
        $data = $request->validate([
            'approve' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $adjustment = $this->service->resolveAdjustment(
            $adjustment, $data['approve'], $data['reason'] ?? null, $request->user()
        );

        return response()->json($adjustment);
    }
}
