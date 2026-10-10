<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Models\PayrollPeriod;
use App\Models\Quote;
use App\Models\Shipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class FinanceController extends Controller
{
    /**
     * Finance portal overview: real payment records (driver payroll payouts),
     * operational counts and reported incidents.
     */
    public function overview(): JsonResponse
    {
        $payments = PayrollPeriod::with('driver')
            ->latest()
            ->take(50)
            ->get()
            ->map(function (PayrollPeriod $p) {
                $total = (float) $p->base + (float) $p->bonuses - (float) $p->deductions;

                return [
                    'ref' => sprintf('PAY-%04d', $p->id),
                    'driver' => $p->driver->name ?? (string) $p->driver_id,
                    'period' => $p->period_label,
                    'base' => (float) $p->base,
                    'bonuses' => (float) $p->bonuses,
                    'deductions' => (float) $p->deductions,
                    'total' => $total,
                    'status' => $p->status,
                    'paid_on' => $p->paid_on,
                    'created_at' => optional($p->created_at)->toDateString(),
                ];
            });

        $paid = $payments->where('status', 'Paid');

        return response()->json([
            'payments' => $payments->values(),
            'summary' => [
                'tx_count' => $payments->count(),
                'paid_count' => $paid->count(),
                'pending_count' => $payments->count() - $paid->count(),
                'paid_total' => (float) $paid->sum('total'),
                'pending_total' => (float) $payments->where('status', '!=', 'Paid')->sum('total'),
                'shipments' => Shipment::count(),
                'quotes' => Quote::count(),
                'incidents' => Incident::count(),
            ],
            'services' => Shipment::select('service_type', DB::raw('count(*) as total'))
                ->groupBy('service_type')
                ->orderByDesc('total')
                ->get()
                ->map(fn ($r) => ['type' => $r->service_type, 'count' => (int) $r->total])
                ->values(),
            'markets' => Shipment::all()
                ->groupBy(function (Shipment $s) {
                    if (preg_match('/^TR3S?-\d{6}-([A-Z]{2})/', (string) $s->tracking_number, $m)) {
                        return $m[1];
                    }
                    $d = mb_strtolower((string) $s->destination);
                    if (preg_match('/santo domingo|santiago|punta cana/', $d)) return 'DO';
                    if (preg_match('/san juan|bayam[oó]n|guaynabo|ponce|carolina/', $d)) return 'PR';
                    if (preg_match('/caracas|valencia|maracaibo|barquisimeto/', $d)) return 'VE';
                    if (preg_match('/miami|orlando|new york|atlanta/', $d)) return 'US';
                    return null;
                })
                ->map(fn ($g, $k) => ['market' => $k, 'count' => $g->count()])
                ->sortByDesc('count')
                ->values(),
            'shipments' => Shipment::latest()
                ->take(50)
                ->get()
                ->map(fn (Shipment $s) => [
                    'tracking' => $s->tracking_number,
                    'service_type' => $s->service_type,
                    'origin' => $s->origin,
                    'destination' => $s->destination,
                    'status' => $s->status,
                    'created_at' => optional($s->created_at)->toDateString(),
                ])
                ->values(),
            'incidents' => Incident::with('driver')
                ->latest()
                ->take(50)
                ->get()
                ->map(fn (Incident $i) => [
                    'code' => $i->code,
                    'title' => $i->title,
                    'category' => $i->category,
                    'severity' => $i->severity,
                    'status' => $i->status,
                    'ship' => $i->ship,
                    'driver' => $i->driver->name ?? null,
                    'photo' => (bool) $i->photo_path,
                    'created_at' => optional($i->created_at)->toDateString(),
                ])
                ->values(),
        ]);
    }
}
