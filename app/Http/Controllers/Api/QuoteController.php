<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\QuoteCreatedMail;
use App\Models\Quote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class QuoteController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'origin' => ['required', 'string', 'max:255'],
            'destination' => ['required', 'string', 'max:255'],
            'service_type' => ['nullable', 'string', 'max:50'],
            'weight' => ['nullable', 'string', 'max:50'],
            'dimensions' => ['nullable', 'string', 'max:50'],
            'pieces' => ['nullable', 'string', 'max:50'],
            'client_name' => ['required', 'string', 'max:255'],
            'client_email' => ['required', 'string', 'email', 'max:255'],
            'details' => ['nullable', 'string'],
        ]);

        $quote = Quote::create([
            'origin' => $validated['origin'],
            'destination' => $validated['destination'],
            'service_type' => $validated['service_type'] ?? null,
            'weight' => $validated['weight'] ?? null,
            'dimensions' => $validated['dimensions'] ?? null,
            'pieces' => $validated['pieces'] ?? null,
            'client_name' => $validated['client_name'],
            'client_email' => $validated['client_email'],
            'details' => $validated['details'] ?? null,
            'status' => 'pending',
            'tracking_code' => $this->generateTrackingCode(),
        ]);

        try {
            Mail::to($quote->client_email)->send(new QuoteCreatedMail($quote));
        } catch (\Throwable $e) {
            // Si el SMTP no está configurado, no fallamos la respuesta.
        }

        return response()->json($quote, 201);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Quote::class);

        $quotes = Quote::orderByDesc('created_at')->paginate(50);

        return response()->json($quotes);
    }

    public function pendingCount(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Quote::class);

        $count = Quote::where('status', 'pending')->count();

        return response()->json(['count' => $count]);
    }

    public function show(Request $request, Quote $quote): JsonResponse
    {
        $this->authorize('view', $quote);

        return response()->json($quote);
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

    public function markViewed(Request $request, Quote $quote): JsonResponse
    {
        $this->authorize('view', $quote);

        if (! $quote->viewed_at) {
            $quote->update(['viewed_at' => now()]);
        }

        return response()->json($quote);
    }

    public function track($trackingCode): JsonResponse
    {
        $quote = Quote::where('tracking_code', $trackingCode)->first();

        if (! $quote) {
            return response()->json(['message' => 'Cotización no encontrada.'], 404);
        }

        return response()->json($quote);
    }

    private function generateTrackingCode(): string
    {
        do {
            $date = now()->format('ymd');

            $letters = '';
            for ($i = 0; $i < 4; $i++) {
                $letters .= chr(random_int(65, 90));
            }

            $numbers = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);

            $code = "TR3-{$date}-{$letters}-{$numbers}";
        } while (Quote::where('tracking_code', $code)->exists());

        return $code;
    }
}
