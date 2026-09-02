<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
                'id' => (string) $driver->id,
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
}
