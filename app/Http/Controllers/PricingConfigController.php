<?php

namespace App\Http\Controllers;

use App\Models\PricingConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PricingConfigController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        if (! $request->user()->hasAnyRole(['admin', 'operations'])) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        return response()->json(PricingConfig::current());
    }

    public function update(Request $request): JsonResponse
    {
        if (! $request->user()->hasAnyRole(['admin'])) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'dim_divisor' => 'required|numeric|min:1',
            'service_limits' => 'nullable|array',
            'service_limits.*' => 'nullable|numeric|min:0',
            'service_rates' => 'nullable|array',
            'service_rates.*.base' => 'nullable|numeric|min:0',
            'service_rates.*.per_lb' => 'nullable|numeric|min:0',
        ]);

        $config = PricingConfig::current();
        $config->update($data);

        return response()->json($config);
    }
}
