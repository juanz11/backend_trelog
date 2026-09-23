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
        // `role_user`, que ya no existe: esa tabla se retiro con Sanctum y el RBAC
        // local. La carga ansiosa de documentos y vehiculos es del equipo (22/9).
        $drivers = User::whereHas('driverProfile')
            ->with(['driverProfile.documents', 'driverProfile.vehicles.documents'])
            ->get();

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
            'invited' => $invitada ?? false,
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
