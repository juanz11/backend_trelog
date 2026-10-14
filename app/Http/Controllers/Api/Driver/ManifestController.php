<?php

namespace App\Http\Controllers\Api\Driver;

use App\Exceptions\ManifestException;
use App\Http\Controllers\Controller;
use App\Models\Manifest;
use App\Models\ManifestDetail;
use App\Services\ManifestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManifestController extends Controller
{
    public function __construct(private readonly ManifestService $service) {}

    /** Manifiestos del chofer autenticado (activos primero). */
    public function index(Request $request): JsonResponse
    {
        $manifests = Manifest::where('driver_id', $request->user()->id)
            ->with(['details', 'transferRequests'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json($manifests->map(fn (Manifest $m) => $m->toApi()));
    }

    public function show(Request $request, Manifest $manifest): JsonResponse
    {
        $this->authorizeManifest($request, $manifest);

        return response()->json($manifest->toApi());
    }

    /** Chofer acepta o rechaza la asignación (rechazo: ASIGNADO → CREADO). */
    public function respondAssignment(Request $request, Manifest $manifest): JsonResponse
    {
        $this->authorizeManifest($request, $manifest);
        $data = $request->validate([
            'accept' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        if ($data['accept']) {
            abort_unless($manifest->status === Manifest::STATUS_ASIGNADO, 422, 'El manifiesto no está pendiente de aceptación');

            return response()->json($manifest->toApi());
        }

        return response()->json(
            $this->service->rejectAssignment($manifest, $data['reason'] ?? null)->toApi()
        );
    }

    public function start(Request $request, Manifest $manifest): JsonResponse
    {
        $this->authorizeManifest($request, $manifest);

        return response()->json($this->service->startRoute($manifest)->toApi());
    }

    /**
     * POD: entrega por QR o ingreso manual (via: QR|MANUAL).
     * Acepta `evidence` (archivo), `evidence_url` o `foto_data_url`.
     */
    public function deliver(Request $request, Manifest $manifest): JsonResponse
    {
        $this->authorizeManifest($request, $manifest);
        $data = $request->validate([
            'detail_id' => ['nullable', 'integer'],
            'code' => ['required_without:detail_id', 'string', 'max:100'],
            'via' => ['nullable', 'in:QR,MANUAL'],
            'evidence' => ['nullable', 'image', 'max:8192'],
            'evidence_url' => ['nullable', 'string', 'max:500'],
            'foto_data_url' => ['nullable', 'string'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'gps_accuracy' => ['nullable', 'numeric'],
            'visited_at' => ['nullable', 'date'],
            'strict' => ['nullable', 'boolean'],
        ]);

        $meta = [
            'lat' => $data['lat'] ?? null,
            'lng' => $data['lng'] ?? null,
            'gps_accuracy' => $data['gps_accuracy'] ?? null,
            'visited_at' => $data['visited_at'] ?? null,
            'via' => $data['via'] ?? ManifestDetail::VIA_QR,
            'strict' => $data['strict'] ?? true,
        ];

        if ($request->hasFile('evidence')) {
            $meta['evidence_path'] = $request->file('evidence')->store('pod-evidence', 'public');
        } else {
            $meta['evidence_url'] = $data['evidence_url'] ?? $data['foto_data_url'] ?? null;
        }

        if (! empty($data['detail_id'])) {
            $detail = $manifest->details()->find($data['detail_id'])
                ?? throw new ManifestException('DETALLE_NO_EXISTE', 'Detalle no encontrado');
            $updated = $this->service->deliver($manifest, $detail, $meta);
        } else {
            $updated = $this->service->deliverByCode($manifest, $data['code'], $meta);
        }

        return response()->json($updated->toApi());
    }

    /** Incidencia de entrega: motivo obligatorio (NO_ENTREGADO). */
    public function fail(Request $request, Manifest $manifest): JsonResponse
    {
        $this->authorizeManifest($request, $manifest);
        $data = $request->validate([
            'detail_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:255'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $detail = $manifest->details()->find($data['detail_id'])
            ?? throw new ManifestException('DETALLE_NO_EXISTE', 'Detalle no encontrado');

        return response()->json(
            $this->service->fail($manifest, $detail, $data['reason'], $data)->toApi()
        );
    }

    /** Solicitud de traspaso (soporta selección parcial de paradas). */
    public function requestTransfer(Request $request, Manifest $manifest): JsonResponse
    {
        $this->authorizeManifest($request, $manifest);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'detail_ids' => ['nullable', 'array'],
            'detail_ids.*' => ['integer'],
        ]);

        $solicitud = $this->service->requestTransfer(
            $manifest,
            $data['reason'],
            $data['detail_ids'] ?? null,
            $request->user()
        );

        return response()->json([
            'solicitud' => $solicitud,
            'manifiesto' => $manifest->fresh()->toApi(),
        ], 201);
    }

    /** El chofer confirma que la asistencia vial llegó. */
    public function resolveAssistance(Request $request, Manifest $manifest): JsonResponse
    {
        $this->authorizeManifest($request, $manifest);

        return response()->json($this->service->resolveAssistance($manifest)->toApi());
    }

    public function finalize(Request $request, Manifest $manifest): JsonResponse
    {
        $this->authorizeManifest($request, $manifest);

        return response()->json($this->service->finalize($manifest)->toApi());
    }

    /* ------------------------- Handover ----------------------------- */

    /**
     * Escaneo físico en el punto de avería. Lo puede ejecutar el chofer
     * origen (scan-out) o el de reemplazo (scan-in).
     */
    public function handoverScan(Request $request, Manifest $manifest): JsonResponse
    {
        $this->authorizeHandover($request, $manifest);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'phase' => ['required', 'in:out,in'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        return response()->json(
            $this->service->handoverScan($manifest, $data['code'], $data['phase'], $request->user(), $data['lat'] ?? null, $data['lng'] ?? null)
        );
    }

    public function handoverStatus(Request $request, Manifest $manifest): JsonResponse
    {
        $this->authorizeHandover($request, $manifest);

        return response()->json($this->service->handoverStatus($manifest));
    }

    public function handoverClose(Request $request, Manifest $manifest): JsonResponse
    {
        $this->authorizeHandover($request, $manifest);

        return response()->json($this->service->closeHandover($manifest));
    }

    private function authorizeManifest(Request $request, Manifest $manifest): void
    {
        abort_if($manifest->driver_id !== $request->user()->id, 403);
    }

    /** El handover lo ejecutan el chofer del derivado o el del manifiesto origen. */
    private function authorizeHandover(Request $request, Manifest $manifest): void
    {
        $userId = $request->user()->id;
        $originDriver = $manifest->origin?->driver_id;
        abort_unless(
            $manifest->driver_id === $userId || $originDriver === $userId,
            403
        );
    }
}
