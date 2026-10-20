<?php

namespace App\Http\Controllers;

use App\Models\DriverAlert;
use App\Models\Quote;
use App\Models\Shipment;
use App\Models\ShipmentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $sameDayRequests = ShipmentRequest::with(['shipment:id,tracking_number', 'client:id,name'])
            ->where('type', 'same_day')
            ->where('status', 'pending')
            ->get();

        foreach ($sameDayRequests as $req) {
            $tracking = $req->shipment->tracking_number ?? 'ENV-'.$req->shipment_id;
            $alerts[] = [
                'type' => 'warning',
                'title' => 'Envío de mismo día',
                'message' => "El cliente ".($req->client->name ?? 'N/D')." solicita entrega el mismo día para {$tracking}.",
                'reference' => $tracking,
                'link' => '/shipments/'.$req->shipment_id,
                'created_at' => $req->created_at,
            ];
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

    /**
     * Personal notifications for the authenticated user (client or driver).
     * Stored in driver_alerts, keyed by user id.
     */
    public function mine(Request $request): JsonResponse
    {
        $alerts = DriverAlert::where('driver_id', $request->user()->id)
            ->latest()
            ->limit(20)
            ->get(['id', 'title', 'body', 'read_at', 'created_at']);

        return response()->json($alerts);
    }

    /**
     * Mark one of the authenticated user's notifications as viewed.
     */
    public function markRead(Request $request, int $id): JsonResponse
    {
        DriverAlert::where('driver_id', $request->user()->id)
            ->where('id', $id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
