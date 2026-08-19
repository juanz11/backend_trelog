<?php

namespace App\Http\Controllers;

use App\Models\Quote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuoteController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'origin' => 'required|string|max:255',
            'destination' => 'required|string|max:255',
            'service_type' => 'nullable|string|max:255',
            'weight' => 'nullable|string|max:255',
            'dimensions' => 'nullable|string|max:255',
            'pieces' => 'nullable|string|max:255',
            'client_name' => 'required|string|max:255',
            'client_email' => 'required|email|max:255',
            'details' => 'nullable|string',
        ]);

        $data['status'] = 'pending';

        Quote::create($data);

        return response()->json(['message' => 'Cotización recibida.'], 201);
    }

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Quote::class);

        return response()->json(Quote::orderByDesc('created_at')->get());
    }

    public function pendingCount(): JsonResponse
    {
        $this->authorize('viewAny', Quote::class);

        return response()->json([
            'count' => Quote::where('status', 'pending')->count(),
        ]);
    }
}
