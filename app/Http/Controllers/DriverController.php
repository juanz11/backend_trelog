<?php

namespace App\Http\Controllers;

use App\Models\DriverProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\Sso\SsoAppClient;
use App\Support\Sso\Espejo;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class DriverController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // `drivers.manage` lo tenian exactamente operations y admin (§D.3).
        if (!$request->user()->hasAnyRole(['admin', 'operations'])) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $drivers = User::whereHas('roles', function ($query) {
            $query->where('name', 'driver');
        })->with('driverProfile')->get();

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

        if ($request->attributes->has('sso_user') && ! $request->filled('user_id')) {
            // POR EL GATEWAY el conductor nuevo NO se crea con contraseña: la persona
            // ya existe en el SSO (se registro en TR3SLOG y quedo como cliente) y
            // operaciones la PROMUEVE. El SSO le asigna `treslog:driver`; aca queda
            // la fila espejo y el DriverProfile. Si no existe en el SSO, todavia no
            // hay forma de crearla desde aca (invitaciones, pendiente): se le pide
            // que ingrese una vez.
            $validated = $request->validate(array_merge($baseRules, [
                'email' => ['required', 'string', 'email', 'max:255'],
                'name' => ['nullable', 'string', 'max:255'],
            ]));

            try {
                $persona = $sso->buscarPorCorreo($validated['email']);
                if ($persona === null) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Esa persona todavia no se registro en TR3SLOG. Pedile que ingrese una vez con su correo y volve a intentarlo.',
                    ], 422);
                }
                $persona = $sso->asignarRol($persona['id'], (string) config('sso.roles.driver'));
            } catch (\Illuminate\Http\Client\RequestException|\Illuminate\Http\Client\ConnectionException $e) {
                Log::error('alta de conductor: el SSO no respondio', ['email' => $validated['email'], 'error' => $e->getMessage()]);

                return response()->json(['success' => false, 'message' => 'El SSO no respondio. Intentalo de nuevo en un momento.'], 502);
            }

            $user = Espejo::asegurar($persona['id'], $persona['email'], $persona['name'] ?: ($validated['name'] ?? null));
            if (! empty($validated['phone'])) {
                $user->phone = $validated['phone'];
                $user->save();
            }
            // El rol local se mantiene por el camino sin gateway (User.php,
            // comportamiento (c)); por el gateway manda lo que dice el SSO.
            $driverRole = Role::where('name', 'driver')->first();
            if ($driverRole && ! $user->roles()->where('roles.id', $driverRole->id)->exists()) {
                $user->roles()->attach($driverRole);
            }
        } elseif ($request->filled('user_id')) {
            $validated = $request->validate(array_merge($baseRules, [
                'user_id' => ['required', 'exists:users,id'],
                'name' => ['nullable', 'string', 'max:255'],
                'password' => ['nullable', 'string', 'regex:/^(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}$/'],
            ]));

            $user = User::with('driverProfile')->findOrFail($validated['user_id']);

            // Es una pregunta sobre OTRA persona y sobre la tabla local (si ya
            // tiene la fila de conductor), no sobre la autorizacion de quien
            // llama: va directo a `role_user`. `hasRole()` sobre un `findOrFail`
            // por el camino del SSO lanzaria (User.php, comportamiento (b)).
            if ($user->roles()->where('name', 'driver')->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'El usuario ya es conductor.',
                ], 422);
            }

            if (! empty($validated['name'])) {
                $user->name = $validated['name'];
            }
            if (! empty($validated['phone'])) {
                $user->phone = $validated['phone'];
            }
            if (! empty($validated['password'])) {
                $user->password = Hash::make($validated['password']);
            }
            $user->save();

            $driverRole = Role::where('name', 'driver')->first();
            if ($driverRole && ! $user->roles()->where('roles.id', $driverRole->id)->exists()) {
                $user->roles()->attach($driverRole);
            }
        } else {
            $validated = $request->validate(array_merge($baseRules, [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
                'password' => ['required', 'string', 'regex:/^(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}$/'],
            ]));

            $user = User::create([
                'name' => $validated['name'],
                'phone' => $validated['phone'] ?? null,
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
            ]);

            $driverRole = Role::where('name', 'driver')->first();
            if ($driverRole) {
                $user->roles()->attach($driverRole);
            }
        }

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
