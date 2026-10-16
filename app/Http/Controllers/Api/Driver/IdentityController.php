<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdentityController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $profile = $request->user()->driverProfile;

        return response()->json([
            'status' => $profile?->verification_status ?? 'unverified',
            'documents' => $profile
                ? $profile->documents->map(fn ($d) => [
                    'type' => $d->type,
                    'url' => $d->file_path ? url('storage/' . $d->file_path) : null,
                ])->values()
                : [],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'id_front' => ['required', 'image', 'max:5120'],
            'id_back' => ['required', 'image', 'max:5120'],
            'selfie' => ['required', 'image', 'max:5120'],
            'consent' => ['accepted'],
        ]);

        $profile = $request->user()->driverProfile()->firstOrCreate([]);

        $profile->documents()
            ->whereIn('type', ['id_front', 'id_back', 'selfie'])
            ->delete();

        foreach (['id_front', 'id_back', 'selfie'] as $type) {
            if ($request->hasFile($type)) {
                $profile->documents()->create([
                    'type' => $type,
                    'file_path' => $request->file($type)->store('identity-docs', 'public'),
                ]);
            }
        }

        $profile->update([
            'verification_status' => 'pending',
            'consent_at' => now(),
        ]);

        return response()->json(['status' => 'pending'], 201);
    }
}
