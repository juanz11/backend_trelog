<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Models\Shipment;
use App\Models\User;
use App\Services\ShipmentDispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShipmentController extends Controller
{
    public function __construct(private ShipmentDispatchService $dispatch)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Shipment::class);

        $user = $request->user();
        $query = Shipment::query();

        if ($user->hasAnyRole(['admin', 'operations'])) {
            // admin/operations: see all shipments
        } else {
            $query->where('user_id', $user->id);
        }

        $shipments = $query->with(['driver.driverProfile'])->orderByDesc('created_at')->get();

        // Add parsed tracking data to each shipment and reflect open incidents
        $shipments->transform(function ($shipment) {
            $shipment->parsed_tracking = $shipment->getParsedTracking();
            $shipment->tracking_url = $shipment->getTrackingUrl();

            if ($this->hasOpenIncident($shipment)) {
                $shipment->status = 'incident';
            }

            return $shipment;
        });

        return response()->json($shipments);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Shipment::class);

        $data = $request->validate([
            'tracking_number' => 'nullable|string|max:255',
            'origin' => 'nullable|string|max:255',
            'destination' => 'nullable|string|max:255',
            'recipient_name' => 'nullable|string|max:255',
            'recipient_email' => 'nullable|email|max:255',
            'recipient_phone' => 'nullable|string|max:255',
            'service_type' => 'nullable|string|max:255',
            'packages' => 'nullable|array',
            'packages.*.pieces' => 'nullable|string|max:255',
            'packages.*.weight' => 'nullable|numeric|min:0.01',
            'packages.*.weight_unit' => 'nullable|string|in:kg,lb',
            'packages.*.dimensions' => 'nullable|string|max:255',
            'packages.*.declared_value' => 'nullable|string|max:255',
            'packages.*.content' => 'nullable|string',
            'weight' => 'nullable|numeric|min:0.01',
            'weight_unit' => 'nullable|string|in:kg,lb',
            'dimensions' => 'nullable|string|max:255',
            'pieces' => 'nullable|string|max:255',
            'status' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $data['status'] = $data['status'] ?? 'pending';
        $data['user_id'] = $request->user()->id;

        $shipment = Shipment::create($data);

        return response()->json($shipment, 201);
    }

    public function show(string $id): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);

        $this->authorize('view', $shipment);

        $shipment->load('driver.driverProfile');
        $shipment->parsed_tracking = $shipment->getParsedTracking();
        $shipment->tracking_url = $shipment->getTrackingUrl();

        if ($this->hasOpenIncident($shipment)) {
            $shipment->status = 'incident';
        }

        return response()->json($shipment);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);

        $this->authorize('update', $shipment);

        $data = $request->validate([
            'tracking_number' => 'nullable|string|max:255',
            'origin' => 'nullable|string|max:255',
            'destination' => 'nullable|string|max:255',
            'recipient_name' => 'nullable|string|max:255',
            'recipient_email' => 'nullable|email|max:255',
            'recipient_phone' => 'nullable|string|max:255',
            'service_type' => 'nullable|string|max:255',
            'packages' => 'nullable|array',
            'packages.*.pieces' => 'nullable|string|max:255',
            'packages.*.weight' => 'nullable|numeric|min:0.01',
            'packages.*.weight_unit' => 'nullable|string|in:kg,lb',
            'packages.*.dimensions' => 'nullable|string|max:255',
            'packages.*.declared_value' => 'nullable|string|max:255',
            'packages.*.content' => 'nullable|string',
            'weight' => 'nullable|numeric|min:0.01',
            'weight_unit' => 'nullable|string|in:kg,lb',
            'dimensions' => 'nullable|string|max:255',
            'pieces' => 'nullable|string|max:255',
            'status' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $status = $data['status'] ?? null;
        unset($data['status']);

        if ($data) {
            $shipment->update($data);
        }

        // A status change has to reach the driver route/stop as well, so it is
        // applied through the dispatch service instead of a plain update.
        if ($status !== null && $status !== $shipment->status) {
            $shipment = $this->dispatch->applyStatus($shipment, $status);
        }

        $shipment->parsed_tracking = $shipment->getParsedTracking();
        $shipment->tracking_url = $shipment->getTrackingUrl();

        return response()->json($shipment);
    }

    public function assignDriver(Request $request, Shipment $shipment): JsonResponse
    {
        $this->authorize('assignDriver', $shipment);

        $data = $request->validate([
            'driver_id' => ['nullable', 'string'],
        ]);

        $driver = null;
        if (! empty($data['driver_id'])) {
            // Un conductor es quien tiene fila en driver_profiles (Lote 9): llego de
            // main con `whereHas('roles', driver)`, y `roles()` ya no existe. Se
            // acepta el id local o el codigo DR-000001.
            $driver = User::whereHas('driverProfile')
                ->where(function ($query) use ($data) {
                    $query->where('id', $data['driver_id'])
                        ->orWhereHas('driverProfile', fn ($profile) => $profile->where('driver_id', $data['driver_id']));
                })
                ->first();

            if (! $driver) {
                return response()->json(['message' => 'El conductor seleccionado no existe o no tiene perfil de conductor.'], 422);
            }
        }

        $shipment = $this->dispatch->assignDriver($shipment, $driver);

        $shipment->parsed_tracking = $shipment->getParsedTracking();
        $shipment->tracking_url = $shipment->getTrackingUrl();

        return response()->json([
            'message' => $driver ? 'Conductor asignado correctamente.' : 'Conductor removido correctamente.',
            'shipment' => $shipment,
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);

        $this->authorize('delete', $shipment);

        $shipment->delete();

        return response()->json(['message' => 'Envío eliminado.']);
    }

    private function hasOpenIncident(Shipment $shipment): bool
    {
        return Incident::where(function ($query) use ($shipment) {
            $query->where('ship', $shipment->tracking_number)
                ->orWhere('ship', (string) $shipment->id);
        })->whereIn('status', ['open', 'investigating'])->exists();
    }
}
