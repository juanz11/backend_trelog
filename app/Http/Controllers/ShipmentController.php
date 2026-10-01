<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Models\Shipment;
use App\Models\ShipmentPackage;
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
            'packages.*.type' => 'nullable|string|in:package,box,envelope,pallet,other',
            'packages.*.pieces' => 'nullable|string|max:255',
            'packages.*.weight' => 'nullable|numeric|min:0.01',
            'packages.*.weight_unit' => 'nullable|string|in:kg,lb',
            'packages.*.length_cm' => 'nullable|numeric|min:0',
            'packages.*.width_cm' => 'nullable|numeric|min:0',
            'packages.*.height_cm' => 'nullable|numeric|min:0',
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

        $packages = $data['packages'] ?? [];
        unset($data['packages']);

        $shipment = Shipment::create($data);
        $this->syncPackages($shipment, is_array($packages) ? $packages : []);

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
            'packages.*.type' => 'nullable|string|in:package,box,envelope,pallet,other',
            'packages.*.pieces' => 'nullable|string|max:255',
            'packages.*.weight' => 'nullable|numeric|min:0.01',
            'packages.*.weight_unit' => 'nullable|string|in:kg,lb',
            'packages.*.length_cm' => 'nullable|numeric|min:0',
            'packages.*.width_cm' => 'nullable|numeric|min:0',
            'packages.*.height_cm' => 'nullable|numeric|min:0',
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
        $packages = array_key_exists('packages', $data) ? $data['packages'] : null;
        unset($data['status'], $data['packages']);

        if ($data) {
            $shipment->update($data);
        }

        if (is_array($packages)) {
            $this->syncPackages($shipment, $packages);
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
            $driver = User::whereHas('roles', fn ($query) => $query->where('name', 'driver'))
                ->where(function ($query) use ($data) {
                    $query->where('id', $data['driver_id'])
                        ->orWhereHas('driverProfile', fn ($profile) => $profile->where('driver_id', $data['driver_id']));
                })
                ->first();

            if (! $driver) {
                return response()->json(['message' => 'El conductor seleccionado no existe o no tiene el rol driver.'], 422);
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

    /**
     * Persist the shipment's packages in the normalized shipment_packages
     * table and keep the packages JSON column in sync for display readers.
     */
    private function syncPackages(Shipment $shipment, array $packages): void
    {
        $shipment->packageItems()->delete();

        $normalized = [];

        foreach ($packages as $pkg) {
            if (! is_array($pkg)) {
                continue;
            }

            $length = isset($pkg['length_cm']) && is_numeric($pkg['length_cm']) ? (float) $pkg['length_cm'] : null;
            $width = isset($pkg['width_cm']) && is_numeric($pkg['width_cm']) ? (float) $pkg['width_cm'] : null;
            $height = isset($pkg['height_cm']) && is_numeric($pkg['height_cm']) ? (float) $pkg['height_cm'] : null;

            // Fallback: parse a "LxWxH cm" display string when numeric fields are absent.
            if ((! $length || ! $width) && ! empty($pkg['dimensions'])
                && preg_match('/(\d+(?:\.\d+)?)\s*[x×]\s*(\d+(?:\.\d+)?)\s*(?:[x×]\s*(\d+(?:\.\d+)?))?/i', $pkg['dimensions'], $m)) {
                $length = $length ?: (float) $m[1];
                $width = $width ?: (float) $m[2];
                $height = $height ?: (isset($m[3]) && $m[3] !== '' ? (float) $m[3] : null);
            }

            $dimValues = array_filter([$length, $width, $height], fn ($v) => $v !== null);
            $row = [
                'type' => $pkg['type'] ?? 'package',
                'pieces' => max(1, (int) ($pkg['pieces'] ?? 1)),
                'weight' => isset($pkg['weight']) && is_numeric($pkg['weight']) ? (float) $pkg['weight'] : null,
                'weight_unit' => $pkg['weight_unit'] ?? 'kg',
                'length_cm' => $length,
                'width_cm' => $width,
                'height_cm' => $height,
                'volume_m3' => ShipmentPackage::volumeM3($length, $width, $height),
                'declared_value' => isset($pkg['declared_value']) && is_numeric($pkg['declared_value']) ? (float) $pkg['declared_value'] : null,
                'content' => $pkg['content'] ?? null,
            ];

            $shipment->packageItems()->create($row);

            $normalized[] = $row + [
                'dimensions' => $pkg['dimensions'] ?? ($dimValues ? implode('x', $dimValues) . ' cm' : null),
                'declared_value' => $pkg['declared_value'] ?? $row['declared_value'],
            ];
        }

        $shipment->update(['packages' => $normalized]);
    }

    private function hasOpenIncident(Shipment $shipment): bool
    {
        return Incident::where(function ($query) use ($shipment) {
            $query->where('ship', $shipment->tracking_number)
                ->orWhere('ship', (string) $shipment->id);
        })->whereIn('status', ['open', 'investigating'])->exists();
    }
}
