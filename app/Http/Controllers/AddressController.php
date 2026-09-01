<?php

namespace App\Http\Controllers;

use App\Models\Address;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AddressController extends Controller
{
    private const ZIP_PATTERNS = [
        'PR' => '/^\d{5}(-\d{4})?$/',
        'US' => '/^\d{5}(-\d{4})?$/',
        'DO' => '/^\d{5}$/',
        'JM' => '/^[A-Z0-9\s-]{3,10}$/i',
        'KR' => '/^\d{5}$/',
        'JP' => '/^\d{3}[- ]?\d{4}$/',
        'CN' => '/^\d{6}$/',
        'VE' => '/^\d{4,5}$/',
        'UY' => '/^\d{5}$/',
    ];

    private const PHONE_PATTERNS = [
        'PR' => '/^(\+?1\s?)?\(?\d{3}\)?\s?\d{3}\s?-?\d{4}$/',
        'DO' => '/^(\+?1\s?)?\(?\d{3}\)?\s?\d{3}\s?-?\d{4}$/',
        'US' => '/^(\+?1\s?)?\(?\d{3}\)?\s?\d{3}\s?-?\d{4}$/',
        'JM' => '/^(\+?1\s?)?\(?\d{3}\)?\s?\d{3}\s?-?\d{4}$/',
        'KR' => '/^(\+82\s?)?\d{2,3}[-.\s]?\d{3,4}[-.\s]?\d{4}$/',
        'JP' => '/^(\+81\s?)?\d{1,2}[-.\s]?\d{4}[-.\s]?\d{4}$/',
        'CN' => '/^(\+86\s?)?\d{11}$/',
        'VE' => '/^(\+58\s?)?\d{3}\s?\d{7}$/',
        'UY' => '/^(\+598\s?)?\d{2,3}\s?\d{3}\s?\d{3}$/',
    ];

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
        $rules = [
            'type' => 'required|in:pickup,delivery,billing',
            'name' => 'nullable|string|max:255',
            'address' => 'required|string',
            'city' => 'nullable|string|max:255',
            'country' => 'required|string|max:2',
            'zip_code' => 'required|string|max:20',
            'contact_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'delivery_instructions' => 'nullable|string',
            'is_default' => 'nullable|boolean',
        ];

        $country = $request->input('country');
        if ($country) {
            $zipPattern = self::ZIP_PATTERNS[$country] ?? '/^[A-Z0-9\s-]{3,15}$/i';
            $rules['zip_code'][] = 'regex:' . $zipPattern;
            if ($request->filled('phone')) {
                $phonePattern = self::PHONE_PATTERNS[$country] ?? '/^[+\d\s\-()]{7,20}$/';
                $rules['phone'][] = 'regex:' . $phonePattern;
            }
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

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

        $rules = [
            'type' => 'sometimes|in:pickup,delivery,billing',
            'name' => 'nullable|string|max:255',
            'address' => 'sometimes|string',
            'city' => 'nullable|string|max:255',
            'country' => 'sometimes|string|max:2',
            'zip_code' => 'sometimes|string|max:20',
            'contact_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'delivery_instructions' => 'nullable|string',
            'is_default' => 'nullable|boolean',
        ];

        $country = $request->input('country');
        if ($country) {
            $zipPattern = self::ZIP_PATTERNS[$country] ?? '/^[A-Z0-9\s-]{3,15}$/i';
            $rules['zip_code'][] = 'regex:' . $zipPattern;
            if ($request->filled('phone')) {
                $phonePattern = self::PHONE_PATTERNS[$country] ?? '/^[+\d\s\-()]{7,20}$/';
                $rules['phone'][] = 'regex:' . $phonePattern;
            }
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

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
