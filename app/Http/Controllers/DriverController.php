<?php

namespace App\Http\Controllers;

use App\Models\DriverProfile;
use App\Models\User;
use App\Services\Sso\SsoAppClient;
use App\Support\Sso\Espejo;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // `drivers.manage` lo tenian exactamente operations y admin (§D.3).
        if (!$request->user()->hasAnyRole(['admin', 'operations'])) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        // Un conductor es una persona con fila en `driver_profiles` (Lote 9): el
        // rol `treslog:driver` lo da el SSO y es lo que le abre la app, pero el
        // padron de la consola es local y nace en `store()`. Antes se listaba por
        // `role_user`, que ya no existe.
        $drivers = User::whereHas('driverProfile')->with('driverProfile')->get();

        $mapped = $drivers->map(function ($driver) {
            $profile = $driver->driverProfile;
            $available = $profile?->available ?? false;

            return [
                'id' => $profile?->driver_id ? (string) $profile->driver_id : (string) $driver->id,
                'n' => $profile?->initials ?? $driver->name,
                'name' => $driver->name,
                'email' => $driver->email,
                'phone' => $driver->phone ?? '—',
                'v' => $profile?->vehicle ?? '—',
                'hub' => $profile?->hub ?? '—',
                'shift' => $profile?->shift ?? '—',
                'doc' => 'ok',
                'st' => $available ? 'available' : 'offduty',
            ];
        });

        return response()->json($mapped);
    }

    public function store(Request $request, SsoAppClient $sso): JsonResponse
    {
        if (! $request->user()->hasAnyRole(['admin', 'operations'])) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $baseRules = [
            'phone' => ['nullable', 'string', 'max:40'],
            'vehicle' => ['nullable', 'string', 'max:255'],
            'hub' => ['nullable', 'string', 'max:255'],
            'shift' => ['nullable', 'string', 'max:255'],
        ];

        // El conductor nuevo NO se crea con contraseña (Lote 8/9): el SSO encuentra
        // a la persona (y la promueve a `treslog:driver`) o la invita por correo
        // con ese rol (alta-usuarios-por-invitacion). Aca queda la fila espejo y
        // el DriverProfile desde ya; la persona entra cuando acepte.
        $validated = $request->validate(array_merge($baseRules, [
            'email' => ['required', 'string', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
        ]));

        try {
            // El SSO encuentra a la persona o la INVITA por correo (Clerk) con el
            // rol de conductor; en ambos casos devuelve su id.
            $resultado = $sso->crearOEncontrar($validated['email'], $validated['name'] ?? null, (string) config('sso.roles.driver'));
            $persona = $resultado['persona'];
            $invitada = $resultado['creada'];
        } catch (\Illuminate\Http\Client\RequestException|\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('alta de conductor: el SSO no respondio', ['email' => $validated['email'], 'error' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => 'El SSO no respondio. Intentalo de nuevo en un momento.'], 502);
        }

        $user = Espejo::asegurar($persona['id'], $persona['email'], $persona['name'] ?: ($validated['name'] ?? null));
        if (! empty($validated['phone'])) {
            $user->phone = $validated['phone'];
            $user->save();
        }
        $user->load('driverProfile');

        $profile = $user->driverProfile;
        if (! $profile) {
            $profile = new DriverProfile([
                'initials' => $this->initialsFromName($user->name),
                'vehicle' => $validated['vehicle'] ?? null,
                'hub' => $validated['hub'] ?? null,
                'shift' => $validated['shift'] ?? null,
                'available' => true,
            ]);
            $user->driverProfile()->save($profile);
        } else {
            if (! empty($validated['vehicle'])) {
                $profile->vehicle = $validated['vehicle'];
            }
            if (! empty($validated['hub'])) {
                $profile->hub = $validated['hub'];
            }
            if (array_key_exists('shift', $validated)) {
                $profile->shift = $validated['shift'] ?? null;
            }
        }

        if (! $profile->driver_id) {
            $profile->driver_id = 'DR-' . str_pad((string) $profile->id, 6, '0', STR_PAD_LEFT);
            $profile->save();
        }

        return response()->json([
            'id' => $profile->driver_id,
            'invited' => $invitada ?? false,
            'n' => $profile->initials ?? $user->name,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone ?? '—',
            'v' => $profile->vehicle ?? '—',
            'hub' => $profile->hub ?? '—',
            'shift' => $profile->shift ?? '—',
            'doc' => 'ok',
            'st' => 'available',
        ], 201);
    }

    private function initialsFromName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name));
        $initials = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return $initials ?: 'DR';
    }
}
