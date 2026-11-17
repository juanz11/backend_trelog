<?php

namespace App\Http\Controllers;

use App\Models\DriverLocation;
use App\Models\Manifest;
use Illuminate\Http\JsonResponse;

class LiveTrackingController extends Controller
{
    public function index(): JsonResponse
    {
        $since = now()->subHours(12);

        $locations = DriverLocation::with('driver.driverProfile')
            ->where('recorded_at', '>=', $since)
            ->orderByDesc('recorded_at')
            ->get();

        $activeManifests = Manifest::with(['details.shipment'])
            ->whereIn('status', ['ASIGNADO', 'EN_RUTA'])
            ->whereIn('driver_id', $locations->pluck('driver_id'))
            ->get()
            ->keyBy('driver_id');

        $payload = $locations->map(function (DriverLocation $loc) use ($activeManifests) {
            $manifest = $activeManifests->get($loc->driver_id);

            return [
                'conductor' => [
                    'id' => $loc->driver_id,
                    'nombre' => $loc->driver?->name,
                    'telefono' => $loc->driver?->phone,
                    'vehiculo' => $loc->driver?->driverProfile?->vehicle,
                ],
                'ubicacion' => [
                    'lat' => $loc->lat,
                    'lng' => $loc->lng,
                    'rumbo' => $loc->heading,
                    'velocidad' => $loc->speed,
                    'precision' => $loc->accuracy,
                    'fecha' => $loc->recorded_at?->toISOString(),
                ],
                'manifiesto' => $manifest ? [
                    'id' => $manifest->id,
                    'codigo' => $manifest->code,
                    'estado' => $manifest->status,
                    'detalles' => $manifest->details->map(fn ($d) => [
                        'id' => $d->id,
                        'secuencia' => $d->sequence,
                        'tracking' => $d->tracking_code,
                        'estado' => $d->status,
                        'destinatario' => $d->shipment?->recipient_name,
                        'destino' => $d->shipment?->destination,
                        'direccion' => $d->shipment?->recipient_address,
                    ])->values(),
                ] : null,
            ];
        })->values();

        return response()->json($payload);
    }
}
