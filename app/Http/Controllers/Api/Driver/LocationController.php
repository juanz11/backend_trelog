<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Models\DriverLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class LocationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'heading' => 'nullable|numeric|between:-360,360',
            'speed' => 'nullable|numeric|min:0',
            'accuracy' => 'nullable|numeric|min:0',
            'recorded_at' => 'nullable|date',
        ]);

        DriverLocation::updateOrCreate(
            ['driver_id' => $request->user()->id],
            [
                'lat' => $data['lat'],
                'lng' => $data['lng'],
                'heading' => $data['heading'] ?? null,
                'speed' => $data['speed'] ?? null,
                'accuracy' => $data['accuracy'] ?? null,
                'recorded_at' => $data['recorded_at'] ?? now(),
            ]
        );

        return response()->json(['ok' => true]);
    }

    /**
     * Proxy a Google Geocoding: convierte la dirección de una parada en
     * coordenadas. El geocoder nativo de Android se cuelga en emuladores y
     * algunos equipos; el resultado se cachea 7 días por dirección.
     */
    public function geocode(Request $request): JsonResponse
    {
        $data = $request->validate(['address' => 'required|string|max:300']);

        $key = config('services.google_maps.key');
        if (! $key) {
            return response()->json(['error' => 'GEOCODE_NOT_CONFIGURED'], 503);
        }

        $cacheKey = 'geocode:'.md5(mb_strtolower(trim($data['address'])));
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return response()->json($cached);
        }

        $res = Http::timeout(10)->get(
            'https://maps.googleapis.com/maps/api/geocode/json',
            [
                'address' => $data['address'],
                'language' => 'es',
                'key' => $key,
            ]
        );

        $body = $res->json();
        $loc = $body['results'][0]['geometry']['location'] ?? null;
        if ($res->ok() && ($body['status'] ?? '') === 'OK' && $loc) {
            $payload = ['status' => 'OK', 'lat' => $loc['lat'], 'lng' => $loc['lng']];
            Cache::put($cacheKey, $payload, now()->addDays(7));

            return response()->json($payload);
        }

        // Respaldo sin key: Nominatim (OpenStreetMap) mantiene viable la
        // ubicación de paradas aunque Google Geocoding no esté habilitado.
        $osm = $this->nominatimGeocode($data['address']);
        if ($osm !== null) {
            Cache::put($cacheKey, $osm, now()->addDays(7));

            return response()->json($osm);
        }

        if ($res->failed()) {
            return response()->json(['error' => 'GEOCODE_UPSTREAM'], 502);
        }

        return response()->json(['status' => $body['status'] ?? 'UNKNOWN']);
    }

    /**
     * Proxy a Google Directions: la key de servicios web vive en el servidor,
     * no en el APK. El chofer envía origen/destino/paradas y recibe la ruta
     * por calles con instrucciones paso a paso para la guía dentro de la app.
     */
    public function directions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'origin' => 'required|string',      // "lat,lng"
            'destination' => 'required|string', // "lat,lng"
            'waypoints' => 'nullable|string',   // "lat,lng|lat,lng"
        ]);

        $key = config('services.google_maps.key');
        if (! $key) {
            // Sin key de Google la ruta por calles la resuelve OSRM.
            return $this->osrmDirections($data);
        }

        $res = Http::timeout(10)->get(
            'https://maps.googleapis.com/maps/api/directions/json',
            array_filter([
                'origin' => $data['origin'],
                'destination' => $data['destination'],
                'waypoints' => $data['waypoints'] ?? null,
                'language' => 'es',
                'mode' => 'driving',
                'key' => $key,
            ])
        );

        $body = $res->json();
        if ($res->failed() || ($body['status'] ?? '') !== 'OK' || empty($body['routes'][0])) {
            // Google rechazado/sin habilitar → OSRM por calles reales.
            return $this->osrmDirections($data);
        }

        $route = $body['routes'][0];

        return response()->json([
            'status' => 'OK',
            'route' => [
                'polyline' => $route['overview_polyline']['points'] ?? '',
                'distance_m' => collect($route['legs'])->sum('distance.value'),
                'duration_s' => collect($route['legs'])->sum('duration.value'),
                'legs' => collect($route['legs'])->map(fn ($leg) => [
                    'distance_m' => $leg['distance']['value'] ?? 0,
                    'duration_s' => $leg['duration']['value'] ?? 0,
                    'end_address' => $leg['end_address'] ?? '',
                    'steps' => collect($leg['steps'] ?? [])->map(fn ($s) => [
                        'instruction' => $s['html_instructions'] ?? '',
                        'distance_m' => $s['distance']['value'] ?? 0,
                        'end_lat' => $s['end_location']['lat'] ?? null,
                        'end_lng' => $s['end_location']['lng'] ?? null,
                        'polyline' => $s['polyline']['points'] ?? '',
                        'maneuver' => $s['maneuver'] ?? null,
                    ]),
                ]),
            ],
        ]);
    }

    /**
     * Geocoding sin Google: Nominatim (OpenStreetMap). Requiere un
     * User-Agent identificable según su política de uso.
     */
    private function nominatimGeocode(string $address): ?array
    {
        try {
            $res = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'tr3slog-driver/1.0 (operaciones@tr3slog.com)'])
                ->get('https://nominatim.openstreetmap.org/search', [
                    'q' => $address,
                    'format' => 'json',
                    'limit' => 1,
                    'countrycodes' => 'do',
                ]);

            $hit = $res->json()[0] ?? null;
            if (! $hit) {
                return null;
            }

            return [
                'status' => 'OK',
                'lat' => (float) $hit['lat'],
                'lng' => (float) $hit['lon'],
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Routing sin Google: OSRM público calcula la ruta siguiendo calles
     * reales (no rectas que cruzan manzanas). Se normaliza a la misma
     * forma que devuelve Google Directions para no tocar los clientes.
     */
    private function osrmDirections(array $data): JsonResponse
    {
        try {
            $points = collect([$data['origin'], $data['destination']]);
            if (! empty($data['waypoints'])) {
                $points = collect([$data['origin']])
                    ->merge(explode('|', $data['waypoints']))
                    ->merge([$data['destination']]);
            }

            // OSRM pide "lng,lat" (al revés que Google).
            $coords = $points
                ->map(function ($p) {
                    [$lat, $lng] = array_pad(explode(',', $p), 2, null);
                    return trim((string) $lng).','.trim((string) $lat);
                })
                ->implode(';');

            $res = Http::timeout(15)
                ->withHeaders(['User-Agent' => 'tr3slog-driver/1.0'])
                ->get("https://router.project-osrm.org/route/v1/driving/{$coords}", [
                    'overview' => 'full',
                    'geometries' => 'polyline',
                    'steps' => 'true',
                ]);

            $body = $res->json();
            $route = $body['routes'][0] ?? null;
            if ($res->failed() || ($body['code'] ?? '') !== 'Ok' || ! $route) {
                return response()->json([
                    'status' => $body['code'] ?? 'OSRM_ERROR',
                    'route' => null,
                ]);
            }

            return response()->json([
                'status' => 'OK',
                'route' => [
                    'polyline' => $route['geometry'] ?? '',
                    'distance_m' => (int) round($route['distance'] ?? 0),
                    'duration_s' => (int) round($route['duration'] ?? 0),
                    'legs' => collect($route['legs'] ?? [])->map(function ($leg) {
                        $steps = collect($leg['steps'] ?? [])->values();

                        return [
                            'distance_m' => (int) round($leg['distance'] ?? 0),
                            'duration_s' => (int) round($leg['duration'] ?? 0),
                            'end_address' => '',
                            'steps' => $steps->map(function ($s, $i) use ($steps) {
                                // La maniobra de OSRM está al inicio del
                                // step: el "fin" es la maniobra siguiente.
                                $next = $steps->get($i + 1);
                                $loc = $next['maneuver']['location']
                                    ?? $s['maneuver']['location'] ?? null;

                                return [
                                    'instruction' => $this->osrmInstruction($s),
                                    'distance_m' => (int) round($s['distance'] ?? 0),
                                    'end_lat' => $loc[1] ?? null,
                                    'end_lng' => $loc[0] ?? null,
                                    'polyline' => $s['geometry'] ?? '',
                                    'maneuver' => null,
                                ];
                            }),
                        ];
                    }),
                ],
            ]);
        } catch (\Throwable) {
            return response()->json(['status' => 'OSRM_ERROR', 'route' => null]);
        }
    }

    /** Texto de guía en español a partir de la maniobra OSRM. */
    private function osrmInstruction(array $step): string
    {
        $maneuver = $step['maneuver'] ?? [];
        $type = $maneuver['type'] ?? '';
        $modifier = $maneuver['modifier'] ?? '';
        $name = trim((string) ($step['name'] ?? ''));
        $via = $name !== '' && $name !== 'null' ? " por {$name}" : '';

        $dir = match ($modifier) {
            'left' => 'a la izquierda',
            'right' => 'a la derecha',
            'slight left' => 'levemente a la izquierda',
            'slight right' => 'levemente a la derecha',
            'sharp left' => 'cerrado a la izquierda',
            'sharp right' => 'cerrado a la derecha',
            'uturn' => 'un retorno',
            'straight' => 'recto',
            default => $modifier,
        };

        return match ($type) {
            'depart' => "Dirígete{$via}",
            'arrive' => 'Llega a tu destino',
            'turn' => "Gira {$dir}{$via}",
            'new name' => "Continúa{$via}",
            'continue' => trim("Continúa {$dir}{$via}"),
            'merge' => "Incorpórate {$dir}{$via}",
            'on ramp' => "Toma la rampa {$dir}{$via}",
            'off ramp' => "Toma la salida {$dir}{$via}",
            'fork' => "Mantente {$dir}{$via}",
            'end of road' => "Al final de la vía gira {$dir}{$via}",
            'roundabout', 'rotary' => 'En la rotonda toma la salida '
                .($maneuver['exit'] ?? '')."{$via}",
            'exit roundabout', 'exit rotary' => "Sal de la rotonda{$via}",
            'roundabout turn' => "En la rotonda gira {$dir}{$via}",
            'use lane' => "Mantente en el carril{$via}",
            'notification' => "Atención{$via}",
            default => $dir !== '' ? "Continúa {$dir}{$via}" : "Continúa{$via}",
        };
    }
}
