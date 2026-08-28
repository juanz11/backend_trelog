<?php

namespace App\Http\Controllers;

use App\Models\Shipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShipmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Shipment::class);

        $user = $request->user();
        $query = Shipment::query();

        if ($user->hasPermission('shipments.view')) {
            // admin/operaciones: no filtra
        } elseif ($user->hasPermission('shipments.view_own')) {
            $query->where('user_id', $user->id);
        } elseif ($user->hasPermission('shipments.view_assigned')) {
            $query->where('driver_id', $user->driverProfile?->id);
        } else {
            $query->whereNull('id');
        }

        $shipments = $query->orderByDesc('created_at')->get();
        
        // Add parsed tracking data to each shipment
        $shipments->transform(function ($shipment) {
            $shipment->parsed_tracking = $shipment->getParsedTracking();
            $shipment->tracking_url = $shipment->getTrackingUrl();
            return $shipment;
        });

        return response()->json($shipments);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Shipment::class);

        $data = $request->validate([
            'tracking_number' => 'nullable|string|max:255',
            'origin' => 'nullable|string|max:255',
            'destination' => 'nullable|string|max:255',
            'recipient_name' => 'nullable|string|max:255',
            'recipient_email' => 'nullable|email|max:255',
            'recipient_phone' => 'nullable|string|max:255',
            'service_type' => 'nullable|string|max:255',
            'weight' => 'nullable|string|max:255',
            'dimensions' => 'nullable|string|max:255',
            'pieces' => 'nullable|string|max:255',
            'status' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $data['status'] = $data['status'] ?? 'pending';
        $data['user_id'] = $request->user()->id;

        $shipment = Shipment::create($data);

        return response()->json($shipment, 201);
    }

    public function show(string $id): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);

        $this->authorize('view', $shipment);

        $shipment->parsed_tracking = $shipment->getParsedTracking();
        $shipment->tracking_url = $shipment->getTrackingUrl();

        return response()->json($shipment);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);

        $this->authorize('update', $shipment);

        $data = $request->validate([
            'tracking_number' => 'nullable|string|max:255',
            'origin' => 'nullable|string|max:255',
            'destination' => 'nullable|string|max:255',
            'recipient_name' => 'nullable|string|max:255',
            'recipient_email' => 'nullable|email|max:255',
            'recipient_phone' => 'nullable|string|max:255',
            'service_type' => 'nullable|string|max:255',
            'weight' => 'nullable|string|max:255',
            'dimensions' => 'nullable|string|max:255',
            'pieces' => 'nullable|string|max:255',
            'status' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $shipment->update($data);

        return response()->json($shipment);
    }

    public function destroy(string $id): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);

        $this->authorize('delete', $shipment);

        $shipment->delete();

        return response()->json(['message' => 'Envío eliminado.']);
    }
}
