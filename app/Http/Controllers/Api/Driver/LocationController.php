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

        if ($res->failed()) {
            return response()->json(['error' => 'GEOCODE_UPSTREAM'], 502);
        }

        $body = $res->json();
        $loc = $body['results'][0]['geometry']['location'] ?? null;
        if (($body['status'] ?? '') !== 'OK' || ! $loc) {
            return response()->json(['status' => $body['status'] ?? 'UNKNOWN']);
        }

        $payload = ['status' => 'OK', 'lat' => $loc['lat'], 'lng' => $loc['lng']];
        Cache::put($cacheKey, $payload, now()->addDays(7));

        return response()->json($payload);
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
            return response()->json(['error' => 'DIRECTIONS_NOT_CONFIGURED'], 503);
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

        if ($res->failed()) {
            return response()->json(['error' => 'DIRECTIONS_UPSTREAM'], 502);
        }

        $body = $res->json();
        if (($body['status'] ?? '') !== 'OK' || empty($body['routes'][0])) {
            return response()->json([
                'status' => $body['status'] ?? 'UNKNOWN',
                'route' => null,
            ]);
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
}
