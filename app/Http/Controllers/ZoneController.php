<?php

namespace App\Http\Controllers;

use App\Models\Zone;
use Illuminate\Http\Request;

class ZoneController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $zones = Zone::all();
        return response()->json([
            'success' => true,
            'zones' => $zones
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'code' => 'required|string|max:10|unique:zones,code',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'delivery_types' => 'string',
        ]);

        $zone = Zone::create([
            'code' => $request->code,
            'name' => $request->name,
            'description' => $request->description,
            'is_active' => $request->is_active ?? true,
            'delivery_types' => $request->delivery_types ?? 'same-day,standard,express',
        ]);

        return response()->json([
            'success' => true,
            'message' => app()->getLocale() === 'en' ? 'Zone created successfully' : 'Zona creada exitosamente',
            'zone' => $zone
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $zone = Zone::find($id);
        if (!$zone) {
            return response()->json([
                'success' => false,
                'message' => app()->getLocale() === 'en' ? 'Zone not found' : 'Zona no encontrada'
            ], 404);
        }
        return response()->json([
            'success' => true,
            'zone' => $zone
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $zone = Zone::find($id);
        if (!$zone) {
            return response()->json([
                'success' => false,
                'message' => app()->getLocale() === 'en' ? 'Zone not found' : 'Zona no encontrada'
            ], 404);
        }

        $request->validate([
            'code' => 'sometimes|required|string|max:10|unique:zones,code,' . $id,
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'delivery_types' => 'string',
        ]);

        $zone->update($request->all());

        return response()->json([
            'success' => true,
            'message' => app()->getLocale() === 'en' ? 'Zone updated successfully' : 'Zona actualizada exitosamente',
            'zone' => $zone
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $zone = Zone::find($id);
        if (!$zone) {
            return response()->json([
                'success' => false,
                'message' => app()->getLocale() === 'en' ? 'Zone not found' : 'Zona no encontrada'
            ], 404);
        }

        $zone->delete();

        return response()->json([
            'success' => true,
            'message' => app()->getLocale() === 'en' ? 'Zone deleted successfully' : 'Zona eliminada exitosamente'
        ]);
    }
}
