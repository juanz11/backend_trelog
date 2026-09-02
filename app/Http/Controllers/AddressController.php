<?php

namespace App\Http\Controllers;

use App\Models\Address;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AddressController extends Controller
{
    private const ZIP_PATTERNS = [
        'AF' => '/^\d{4}$/',
        'AL' => '/^\d{4}$/',
        'AR' => '/^\d{4}$/',
        'AU' => '/^\d{4}$/',
        'BE' => '/^\d{4}$/',
        'BO' => '/^\d{4}$/',
        'BR' => '/^\d{5}-?\d{3}$/',
        'CA' => '/^[A-Z]\d[A-Z]\s?\d[A-Z]\d$/i',
        'CH' => '/^\d{4}$/',
        'CL' => '/^\d{7}$/',
        'CO' => '/^\d{6}$/',
        'CR' => '/^\d{5}$/',
        'CU' => '/^\d{5}$/',
        'DE' => '/^\d{5}$/',
        'DO' => '/^\d{5}$/',
        'EC' => '/^\d{6}$/',
        'EG' => '/^\d{5}$/',
        'ES' => '/^\d{5}$/',
        'FR' => '/^\d{5}$/',
        'GB' => '/^[A-Z]{1,2}\d[A-Z\d]?\s?\d[A-Z]{2}$/i',
        'GT' => '/^\d{5}$/',
        'HN' => '/^\d{5}$/',
        'IT' => '/^\d{5}$/',
        'JM' => '/^(?=.*[A-Z])[A-Z0-9\s-]{3,10}$/i',
        'JP' => '/^\d{3}[- ]?\d{4}$/',
        'KR' => '/^\d{5}$/',
        'MX' => '/^\d{5}$/',
        'NI' => '/^\d{5}$/',
        'NL' => '/^\d{4}\s?[A-Z]{2}$/i',
        'PA' => '/^\d{4}$/',
        'PE' => '/^\d{5}$/',
        'PR' => '/^\d{5}(-\d{4})?$/',
        'PT' => '/^\d{4}-?\d{3}$/',
        'PY' => '/^\d{4}$/',
        'CN' => '/^\d{6}$/',
        'RU' => '/^\d{6}$/',
        'SV' => '/^\d{4}$/',
        'US' => '/^\d{5}(-\d{4})?$/',
        'UY' => '/^\d{5}$/',
        'VE' => '/^\d{4,5}$/',
        'ZA' => '/^\d{4}$/',
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
            $zipPattern = self::ZIP_PATTERNS[$country] ?? '/^$/';
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
            $zipPattern = self::ZIP_PATTERNS[$country] ?? '/^$/';
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
