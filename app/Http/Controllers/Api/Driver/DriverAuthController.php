<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class DriverAuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'regex:/^(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}$/', 'confirmed'],
            'vehicle' => ['nullable', 'string', 'max:255'],
            'hub' => ['nullable', 'string', 'max:255'],
        ]);

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

        $profile = new DriverProfile([
            'initials' => $this->initialsFromName($validated['name']),
            'vehicle' => $validated['vehicle'] ?? null,
            'hub' => $validated['hub'] ?? null,
        ]);
        $user->driverProfile()->save($profile);

        $profile->driver_id = 'DR-' . str_pad((string) $profile->id, 6, '0', STR_PAD_LEFT);
        $profile->save();

        $token = $user->createToken('driver-app')->plainTextToken;

        return response()->json([
            'user' => $user->fresh('driverProfile'),
            'token' => $token,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'driver_id' => ['nullable', 'string'],
            'email' => ['nullable', 'email'],
            'password' => ['required'],
        ]);

        $identifier = $validated['driver_id'] ?? $validated['email'] ?? null;

        if (! $identifier) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales no son correctas.'],
            ]);
        }

        $email = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? $identifier : null;
        $driverId = $email ? null : $identifier;

        if ($email) {
            $user = User::where('email', $email)->first();
        } else {
            $profile = DriverProfile::where('driver_id', $driverId)->first();
            $user = $profile?->user;
        }

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales no son correctas.'],
            ]);
        }

        if (! $user->hasRole('driver')) {
            throw ValidationException::withMessages([
                'email' => ['Esta cuenta no tiene acceso a la app de conductores.'],
            ]);
        }

        $token = $user->createToken('driver-app')->plainTextToken;

        return response()->json([
            'user' => $user->load('driverProfile'),
            'token' => $token,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user()->load('driverProfile'));
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
