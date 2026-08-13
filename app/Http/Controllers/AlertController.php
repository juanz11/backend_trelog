<?php

namespace App\Http\Controllers;

use App\Models\Quote;
use App\Models\Shipment;
use Illuminate\Http\JsonResponse;

class AlertController extends Controller
{
    public function index(): JsonResponse
    {
        $alerts = [];

        $pendingQuotes = Quote::where('status', 'pending')->get();

        foreach ($pendingQuotes as $quote) {
            $daysLeft = 3 - (int) now()->diffInDays($quote->created_at);

            if ($daysLeft > 0 && $daysLeft <= 2) {
                $alerts[] = [
                    'type' => 'warning',
                    'title' => 'Cotización por aprobar',
                    'message' => "La cotización QT-".str_pad($quote->id, 4, '0', STR_PAD_LEFT)." vence en {$daysLeft} día".($daysLeft === 1 ? '' : 's').".",
                    'reference' => 'QT-'.str_pad($quote->id, 4, '0', STR_PAD_LEFT),
                    'link' => '/quotes/'.$quote->id,
                    'created_at' => $quote->created_at,
                ];
            }
        }

        $incompleteShipments = Shipment::where(function ($query) {
            $query->whereNull('recipient_phone')->orWhere('recipient_phone', '');
        })->get();

        foreach ($incompleteShipments as $shipment) {
            $alerts[] = [
                'type' => 'info',
                'title' => 'Dirección por confirmar',
                'message' => "Falta el teléfono del receptor en ".($shipment->tracking_number ?? 'ENV-'.$shipment->id).".",
                'reference' => $shipment->tracking_number ?? 'ENV-'.$shipment->id,
                'link' => '/shipments/'.$shipment->id,
                'created_at' => $shipment->created_at,
            ];
        }

        return response()->json(
            collect($alerts)->sortByDesc('created_at')->values()
        );
    }
}
