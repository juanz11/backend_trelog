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
        })->with(['driverProfile.documents', 'driverProfile.vehicles.documents'])->get();

        $mapped = $drivers->map(function ($driver) {
            $profile = $driver->driverProfile;
            $available = $profile?->available ?? false;
            $docState = $this->documentState($profile);

            return [
                'id' => $profile?->driver_id ? (string) $profile->driver_id : (string) $driver->id,
                'user_id' => $driver->id,
                'n' => $profile?->initials ?? $driver->name,
                'name' => $driver->name,
                'email' => $driver->email,
                'phone' => $driver->phone ?? '—',
                'v' => $profile?->vehicle ?? '—',
                'hub' => $profile?->hub ?? '—',
                'shift' => $profile?->shift ?? '—',
                'doc' => $docState,
                'st' => $available ? 'available' : 'offduty',
                'documents' => $this->mapDocuments($profile?->documents),
                'vehicles' => ($profile?->vehicles ?? collect())->map(function ($vehicle) {
                    return [
                        'id' => $vehicle->id,
                        'label' => $vehicle->label,
                        'plate' => $vehicle->plate,
                        'cargo_capacity' => $vehicle->cargo_capacity,
                        'documents' => $this->mapDocuments($vehicle->documents),
                    ];
                })->values()->all(),
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
            'shift' => ['nullable', 'string', 'max:255'],
            'id_document_number' => ['required', 'string', 'max:100'],
            'id_document_expires_at' => ['nullable', 'date'],
            'license_number' => ['required', 'string', 'max:100'],
            'license_expires_at' => ['required', 'date'],
            'photo' => ['required', 'image', 'max:5120'],
            'id_document_file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'license_file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'vehicles' => ['nullable', 'array'],
            'vehicles.*.label' => ['nullable', 'string', 'max:255'],
            'vehicles.*.plate' => ['required', 'string', 'max:50'],
            'vehicles.*.cargo_capacity' => ['nullable', 'string', 'max:100'],
            'vehicles.*.registration_number' => ['nullable', 'string', 'max:100'],
            'vehicles.*.insurance_number' => ['nullable', 'string', 'max:100'],
            'vehicles.*.insurance_expires_at' => ['nullable', 'date'],
            'vehicles.*.inspection_number' => ['nullable', 'string', 'max:100'],
            'vehicles.*.inspection_expires_at' => ['nullable', 'date'],
            'vehicles.*.permit_number' => ['nullable', 'string', 'max:100'],
            'vehicles.*.permit_expires_at' => ['nullable', 'date'],
            'vehicles.*.files.*' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
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

        $driverDocs = [
            ['type' => 'id_card', 'number' => $validated['id_document_number'], 'expires_at' => $validated['id_document_expires_at'] ?? null, 'file' => $request->file('id_document_file')],
            ['type' => 'license', 'number' => $validated['license_number'], 'expires_at' => $validated['license_expires_at'], 'file' => $request->file('license_file')],
            ['type' => 'photo', 'number' => null, 'expires_at' => null, 'file' => $request->file('photo')],
        ];
        foreach ($driverDocs as $doc) {
            $profile->documents()->create([
                'type' => $doc['type'],
                'number' => $doc['number'],
                'expires_at' => $doc['expires_at'],
                'file_path' => $doc['file'] ? $doc['file']->store('documents/drivers', 'public') : null,
            ]);
        }

        foreach ((array) $request->input('vehicles', []) as $i => $v) {
            $vehicle = $profile->vehicles()->create([
                'label' => $v['label'] ?? null,
                'plate' => $v['plate'] ?? null,
                'cargo_capacity' => $v['cargo_capacity'] ?? null,
            ]);
            $vehicleDocs = [
                'registration' => ['number' => $v['registration_number'] ?? null, 'expires_at' => null],
                'insurance' => ['number' => $v['insurance_number'] ?? null, 'expires_at' => $v['insurance_expires_at'] ?? null],
                'inspection' => ['number' => $v['inspection_number'] ?? null, 'expires_at' => $v['inspection_expires_at'] ?? null],
                'cargo_permit' => ['number' => $v['permit_number'] ?? null, 'expires_at' => $v['permit_expires_at'] ?? null],
            ];
            foreach ($vehicleDocs as $type => $meta) {
                $file = $request->file("vehicles.$i.files.$type");
                if (! $file && ! $meta['number'] && ! $meta['expires_at']) {
                    continue;
                }
                $vehicle->documents()->create([
                    'type' => $type,
                    'number' => $meta['number'],
                    'expires_at' => $meta['expires_at'],
                    'file_path' => $file ? $file->store('documents/vehicles', 'public') : null,
                ]);
            }
        }

        $profile->load(['documents', 'vehicles.documents']);

        if (empty($profile->vehicle) && $profile->vehicles->isNotEmpty()) {
            $first = $profile->vehicles->first();
            $profile->vehicle = trim(implode(' ', array_filter([$first->label, $first->plate]))) ?: null;
            $profile->save();
        }

        return response()->json([
            'id' => $profile->driver_id,
            'user_id' => $user->id,
            'n' => $profile->initials ?? $user->name,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone ?? '—',
            'v' => $profile->vehicle ?? '—',
            'hub' => $profile->hub ?? '—',
            'shift' => $profile->shift ?? '—',
            'doc' => $this->documentState($profile),
            'st' => 'available',
            'documents' => $this->mapDocuments($profile->documents),
            'vehicles' => $profile->vehicles->map(function ($vehicle) {
                return [
                    'id' => $vehicle->id,
                    'label' => $vehicle->label,
                    'plate' => $vehicle->plate,
                    'cargo_capacity' => $vehicle->cargo_capacity,
                    'documents' => $this->mapDocuments($vehicle->documents),
                ];
            })->values()->all(),
        ], 201);
    }

    private function mapDocuments($documents): array
    {
        return collect($documents ?? [])->map(function ($doc) {
            return [
                'type' => $doc->type,
                'number' => $doc->number,
                'expires_at' => $doc->expires_at?->toDateString(),
                'file_path' => $doc->file_path,
            ];
        })->values()->all();
    }

    private function documentState(?DriverProfile $profile): string
    {
        if (! $profile) {
            return 'ok';
        }

        $docs = collect($profile->documents ?? [])
            ->merge(collect($profile->vehicles ?? [])->flatMap(fn ($v) => $v->documents));

        $state = 'ok';
        foreach ($docs as $doc) {
            if (! $doc->expires_at) {
                continue;
            }
            if ($doc->expires_at->isPast()) {
                return 'expired';
            }
            if ($doc->expires_at->lt(now()->addDays(30))) {
                $state = 'soon';
            }
        }

        return $state;
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
