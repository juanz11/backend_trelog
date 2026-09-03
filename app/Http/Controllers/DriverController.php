<?php

namespace App\Http\Controllers;

use App\Models\DriverProfile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class DriverController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (!$request->user()->hasAnyRole(['admin', 'operations']) && !$request->user()->hasPermission('drivers.manage')) {
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
                'v' => $profile?->vehicle ?? '—',
                'hub' => $profile?->hub ?? '—',
                'shift' => '—',
                'doc' => 'ok',
                'st' => $available ? 'available' : 'offduty',
            ];
        });

        return response()->json($mapped);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->hasAnyRole(['admin', 'operations']) && ! $request->user()->hasPermission('drivers.manage')) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $baseRules = [
            'phone' => ['nullable', 'string', 'max:40'],
            'vehicle' => ['nullable', 'string', 'max:255'],
            'hub' => ['nullable', 'string', 'max:255'],
        ];

        if ($request->filled('user_id')) {
            $validated = $request->validate(array_merge($baseRules, [
                'user_id' => ['required', 'exists:users,id'],
                'name' => ['nullable', 'string', 'max:255'],
                'password' => ['nullable', 'string', 'regex:/^(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}$/'],
            ]));

            $user = User::with('driverProfile')->findOrFail($validated['user_id']);

            if ($user->hasRole('driver')) {
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
        }

        if (! $profile->driver_id) {
            $profile->driver_id = 'DR-' . str_pad((string) $profile->id, 6, '0', STR_PAD_LEFT);
            $profile->save();
        }

        return response()->json([
            'id' => $profile->driver_id,
            'n' => $profile->initials ?? $user->name,
            'v' => $profile->vehicle ?? '—',
            'hub' => $profile->hub ?? '—',
            'shift' => '—',
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
