<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\QuoteCreatedMail;
use App\Helpers\TrackingGenerator;
use App\Models\Quote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

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
            'tracking_code' => TrackingGenerator::generateFromRoute(
                $validated['origin'],
                $validated['destination'],
                $validated['service_type'] ?? ''
            ),
        ]);

        try {
            Mail::to($quote->client_email)->send(new QuoteCreatedMail($quote));
        } catch (\Throwable $e) {
            // Si el SMTP no está configurado, no fallamos la respuesta.
        }

        return response()->json($quote, 201);
    }

    // index/pendingCount/show/updateStatus/markViewed SE FUERON en el Lote 9:
    // vivian bajo `/app/*` con auth:sanctum para una app de clientes que nunca
    // existio (D3). La cotizacion autenticada de la web es App\Http\Controllers\QuoteController.

    /**
     * Tracking publico por codigo, sin cuenta («como UPS», D8.1).
     *
     * Devuelve SOLO lo que la pantalla de seguimiento muestra: estado, tramo,
     * servicio y fechas. Antes devolvia la fila entera (nombre, correo y
     * detalle del cliente), y como el codigo es secuencial por dia y hub
     * (TrackingGenerator), un cupo por IP no alcanza para frenar a quien
     * recorra los codigos de un dia: era una fuga de datos personales publica
     * (revision de jueces 2026-09-16, #4). Lo que no esta en esta lista no sale.
     */
    public function track($trackingCode): JsonResponse
    {
        $quote = Quote::where('tracking_code', $trackingCode)->first();

        if (! $quote) {
            return response()->json(['message' => 'Cotización no encontrada.'], 404);
        }

        return response()->json([
            'tracking_code' => $quote->tracking_code,
            'status' => $quote->status,
            'origin' => $quote->origin,
            'destination' => $quote->destination,
            'service_type' => $quote->service_type,
            'created_at' => $quote->created_at,
            'updated_at' => $quote->updated_at,
        ]);
    }
}
