<?php

namespace App\Http\Controllers;

use App\Models\CompanyVehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CompanyVehicleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            CompanyVehicle::orderByDesc('created_at')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['nullable', 'string', 'max:100'],
            'label' => ['required', 'string', 'max:255'],
            'plate' => ['required', 'string', 'max:50', 'unique:company_vehicles,plate'],
            'cargo_capacity' => ['nullable', 'string', 'max:100'],
            'active' => ['nullable', 'boolean'],
        ]);

        $vehicle = CompanyVehicle::create($data);

        return response()->json($vehicle, 201);
    }

    public function update(Request $request, CompanyVehicle $companyVehicle): JsonResponse
    {
        $data = $request->validate([
            'type' => ['nullable', 'string', 'max:100'],
            'label' => ['nullable', 'string', 'max:255'],
            'plate' => ['nullable', 'string', 'max:50', Rule::unique('company_vehicles', 'plate')->ignore($companyVehicle->id)],
            'cargo_capacity' => ['nullable', 'string', 'max:100'],
            'active' => ['nullable', 'boolean'],
        ]);

        $companyVehicle->update($data);

        return response()->json($companyVehicle);
    }

    public function destroy(CompanyVehicle $companyVehicle): JsonResponse
    {
        $companyVehicle->delete();

        return response()->json(['message' => 'Vehículo eliminado.']);
    }
}
