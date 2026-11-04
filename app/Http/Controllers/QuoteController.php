<?php

namespace App\Http\Controllers;

use App\Helpers\TrackingGenerator;
use App\Mail\QuoteCreatedMail;
use App\Models\Quote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

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
        $data['tracking_code'] = TrackingGenerator::generateFromRoute(
            $data['origin'],
            $data['destination'],
            $data['service_type'] ?? ''
        );

        $quote = Quote::create($data);

        try {
            Mail::to($quote->client_email)->send(new QuoteCreatedMail($quote));
        } catch (\Throwable $e) {
            // Si el SMTP no está configurado, no fallamos la respuesta.
        }

        return response()->json($quote, 201);
    }

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Quote::class);

        return response()->json(Quote::orderByDesc('created_at')->get());
    }

    public function pendingCount(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user && $user->can('quotes.view')) {
            $count = Quote::where('status', 'pending')->count();
        } else {
            $count = Quote::where('client_email', $user->email)
                ->where('status', 'pending')
                ->count();
        }

        return response()->json([
            'count' => $count,
        ]);
    }

    public function updateStatus(Request $request, Quote $quote): JsonResponse
    {
        $this->authorize('update', $quote);

        $data = $request->validate([
            'status' => ['required', 'in:pending,processing,approved,rejected'],
        ]);

        $quote->update(['status' => $data['status']]);

        return response()->json($quote);
    }
}
