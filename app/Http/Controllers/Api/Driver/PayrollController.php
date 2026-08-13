<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Models\PayrollPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayrollController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $periods = PayrollPeriod::where('driver_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json($periods);
    }
}
