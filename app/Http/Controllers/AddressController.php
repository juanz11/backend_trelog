<?php

namespace App\Http\Controllers;

use App\Models\Address;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AddressController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $addresses = $request->user()->addresses()->orderByDesc('created_at')->get();

        if ($addresses->isEmpty()) {
            return response()->json([
                'message' => 'No posee direcciones registradas.',
                'addresses' => [],
            ]);
        }

        return response()->json($addresses);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => 'required|in:pickup,delivery,billing',
            'name' => 'nullable|string|max:255',
            'address' => 'required|string',
            'city' => 'nullable|string|max:255',
            'zip_code' => 'nullable|string|max:20',
            'contact_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'delivery_instructions' => 'nullable|string',
            'is_default' => 'nullable|boolean',
        ]);

        $data['user_id'] = $request->user()->id;

        if (! empty($data['is_default'])) {
            $request->user()->addresses()->where('type', $data['type'])->update(['is_default' => false]);
        }

        $address = Address::create($data);

        return response()->json($address, 201);
    }

    public function show(Request $request, Address $address): JsonResponse
    {
        $this->authorizeOwner($request, $address);

        return response()->json($address);
    }

    public function update(Request $request, Address $address): JsonResponse
    {
        $this->authorizeOwner($request, $address);

        $data = $request->validate([
            'type' => 'sometimes|in:pickup,delivery,billing',
            'name' => 'nullable|string|max:255',
            'address' => 'sometimes|string',
            'city' => 'nullable|string|max:255',
            'zip_code' => 'nullable|string|max:20',
            'contact_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'delivery_instructions' => 'nullable|string',
            'is_default' => 'nullable|boolean',
        ]);

        if (! empty($data['is_default']) && isset($data['type'])) {
            $request->user()->addresses()->where('type', $data['type'])->update(['is_default' => false]);
        }

        $address->update($data);

        return response()->json($address);
    }

    public function destroy(Request $request, Address $address): JsonResponse
    {
        $this->authorizeOwner($request, $address);

        $address->delete();

        return response()->json(['message' => 'Dirección eliminada.']);
    }

    private function authorizeOwner(Request $request, Address $address): void
    {
        if ($address->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            abort(403, 'No autorizado.');
        }
    }
}
