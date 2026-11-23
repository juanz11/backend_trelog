<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Models\LegalAcceptance;
use App\Models\LegalDocument;
use App\Models\Shipment;
use App\Models\ShipmentPackage;
use App\Models\ShipmentRequest;
use App\Models\User;
use App\Services\ShipmentDispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ShipmentController extends Controller
{
    public function __construct(private ShipmentDispatchService $dispatch)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Shipment::class);

        $user = $request->user();
        $query = Shipment::query();

        if ($user->can('shipments.view')) {
            // admin/operations: see all shipments
        } else {
            $query->where('user_id', $user->id);
        }

        $shipments = $query->with(['driver.driverProfile', 'manifestDetail.manifest', 'stop', 'client', 'returnReviewer', 'returnProcessor'])->orderByDesc('created_at')->get();

        // Add parsed tracking data to each shipment and reflect open incidents
        $shipments->transform(function ($shipment) {
            $shipment->parsed_tracking = $shipment->getParsedTracking();
            $shipment->tracking_url = $shipment->getTrackingUrl();
            $shipment->cancel_preview = $this->dispatch->cancellationStage($shipment);
            $this->withClientMeta($shipment);

            if ($this->hasOpenIncident($shipment)) {
                $shipment->status = 'incident';
            }

            $shipment->return_recobro = $this->dispatch->returnRecobro($shipment);

            return $shipment;
        });

        return response()->json($shipments);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Shipment::class);

        $data = $request->validate([
            'tracking_number' => 'nullable|string|max:255',
            'origin' => 'nullable|string|max:255',
            'destination' => 'nullable|string|max:255',
            'recipient_name' => 'nullable|string|max:255',
            'recipient_email' => 'nullable|email|max:255',
            'recipient_phone' => 'nullable|string|max:255',
            'recipient_company' => 'nullable|string|max:255',
            'recipient_address' => 'nullable|string|max:255',
            'recipient_city' => 'nullable|string|max:255',
            'recipient_country' => 'nullable|string|max:255',
            'recipient_zip' => 'nullable|string|max:50',
            'sender_name' => 'nullable|string|max:255',
            'sender_company' => 'nullable|string|max:255',
            'sender_address' => 'nullable|string|max:255',
            'sender_city' => 'nullable|string|max:255',
            'sender_country' => 'nullable|string|max:255',
            'sender_zip' => 'nullable|string|max:50',
            'sender_phone' => 'nullable|string|max:255',
            'sender_email' => 'nullable|email|max:255',
            'service_type' => 'nullable|string|max:255',
            'delivery_mode' => 'nullable|string|in:standard,express,same_day',
            'pickup_date' => 'nullable|date',
            'pickup_window' => 'nullable|string|max:50',
            'packages' => 'nullable|array',
            'packages.*.type' => 'nullable|string|in:package,box,envelope,pallet,other',
            'packages.*.pieces' => 'nullable|string|max:255',
            'packages.*.weight' => 'nullable|numeric|min:0.01',
            'packages.*.weight_unit' => 'nullable|string|in:kg,lb',
            'packages.*.length_cm' => 'nullable|numeric|min:0',
            'packages.*.width_cm' => 'nullable|numeric|min:0',
            'packages.*.height_cm' => 'nullable|numeric|min:0',
            'packages.*.fragile' => 'nullable|boolean',
            'packages.*.dimensions' => 'nullable|string|max:255',
            'packages.*.declared_value' => 'nullable|string|max:255',
            'packages.*.content' => 'nullable|string',
            'weight' => 'nullable|numeric|min:0.01',
            'weight_unit' => 'nullable|string|in:kg,lb',
            'dimensions' => 'nullable|string|max:255',
            'pieces' => 'nullable|string|max:255',
            'status' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'payment_method' => 'nullable|string|in:card,transfer,paypal,affirm,klarna,apple_pay,google_pay',
            'payment_method_id' => 'nullable|string|max:255',
            'payment_bank' => 'nullable|string|max:255',
            'payment_reference' => 'nullable|string|max:255',
            'charge_to_account' => 'nullable|string|max:255',
            'amount' => 'nullable|numeric|min:0',
            'distance_km' => 'nullable|numeric|min:0',
            'prohibited_accepted' => 'required|accepted',
            'prohibited_document_id' => 'required|integer|exists:legal_documents,id',
        ]);

        $user = $request->user();
        if ($user->hasAnyRole(['customer', 'executive_client']) && (is_null($user->email_verified_at) || is_null($user->phone_verified_at))) {
            return response()->json(['message' => 'Debes verificar tu correo y teléfono antes de crear envíos.'], 403);
        }

        $prohibitedDoc = LegalDocument::find($data['prohibited_document_id']);
        if (!$prohibitedDoc || $prohibitedDoc->type !== 'prohibited_items' || !$prohibitedDoc->is_active) {
            return response()->json([
                'message' => 'La lista de artículos prohibidos fue actualizada. Vuelve a revisarla y aceptarla.',
                'errors' => [
                    'prohibited_document_id' => ['La lista de artículos prohibidos fue actualizada. Vuelve a revisarla y aceptarla.'],
                ],
            ], 422);
        }

        $data['status'] = $data['status'] ?? 'pending';
        $data['user_id'] = $request->user()->id;
        $data['prohibited_declared_at'] = now();
        $data['prohibited_list_version'] = $prohibitedDoc->version;
        $data['prohibited_ip'] = $request->ip();

        $packages = $data['packages'] ?? [];
        unset($data['packages'], $data['prohibited_accepted'], $data['prohibited_document_id']);

        $shipment = DB::transaction(function () use ($data, $packages, $prohibitedDoc, $request) {
            $shipment = Shipment::create($data);
            $this->syncPackages($shipment, is_array($packages) ? $packages : []);

            LegalAcceptance::create([
                'user_id' => $request->user()->id,
                'legal_document_id' => $prohibitedDoc->id,
                'shipment_id' => $shipment->id,
                'accepted_at' => $data['prohibited_declared_at'],
                'ip' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 509, '...'),
                'content_hash' => $prohibitedDoc->content_hash,
            ]);

            return $shipment;
        });

        // Same-day shipments open a priority request for the client so it
        // lands in operations → Solicitudes (handled like the old driver flow).
        if (($shipment->delivery_mode ?? null) === 'same_day') {
            ShipmentRequest::create([
                'type' => 'same_day',
                'shipment_id' => $shipment->id,
                'client_id' => $request->user()->id,
                'status' => 'pending',
                'message' => 'Envío de mismo día — atención prioritaria.',
            ]);
        }

        // Factura provisional: monto aleatorio hasta definir la tabla de tarifas.
        $shipment->forceFill([
            'amount' => $data['amount'] ?? (mt_rand(2500, 30000) / 100),
            'invoice_number' => sprintf('REC-%s-%06d', now()->format('y'), $shipment->id),
            'invoiced_at' => now(),
        ])->save();

        $this->withClientMeta($shipment);

        return response()->json($shipment, 201);
    }

    public function show(string $id): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);

        $this->authorize('view', $shipment);

        $shipment->load('driver.driverProfile', 'client', 'manifestDetail.manifest', 'stop', 'returnReviewer', 'returnProcessor');
        $shipment->parsed_tracking = $shipment->getParsedTracking();
        $shipment->tracking_url = $shipment->getTrackingUrl();
        $shipment->cancel_preview = $this->dispatch->cancellationStage($shipment);
        $this->withClientMeta($shipment);

        if ($this->hasOpenIncident($shipment)) {
            $shipment->status = 'incident';
        }

        $shipment->return_recobro = $this->dispatch->returnRecobro($shipment);

        return response()->json($shipment);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);

        $this->authorize('update', $shipment);

        $data = $request->validate([
            'tracking_number' => 'nullable|string|max:255',
            'origin' => 'nullable|string|max:255',
            'destination' => 'nullable|string|max:255',
            'recipient_name' => 'nullable|string|max:255',
            'recipient_email' => 'nullable|email|max:255',
            'recipient_phone' => 'nullable|string|max:255',
            'recipient_company' => 'nullable|string|max:255',
            'recipient_address' => 'nullable|string|max:255',
            'recipient_city' => 'nullable|string|max:255',
            'recipient_country' => 'nullable|string|max:255',
            'recipient_zip' => 'nullable|string|max:50',
            'sender_name' => 'nullable|string|max:255',
            'sender_company' => 'nullable|string|max:255',
            'sender_address' => 'nullable|string|max:255',
            'sender_city' => 'nullable|string|max:255',
            'sender_country' => 'nullable|string|max:255',
            'sender_zip' => 'nullable|string|max:50',
            'sender_phone' => 'nullable|string|max:255',
            'sender_email' => 'nullable|email|max:255',
            'service_type' => 'nullable|string|max:255',
            'delivery_mode' => 'nullable|string|in:standard,express,same_day',
            'pickup_date' => 'nullable|date',
            'pickup_window' => 'nullable|string|max:50',
            'packages' => 'nullable|array',
            'packages.*.type' => 'nullable|string|in:package,box,envelope,pallet,other',
            'packages.*.pieces' => 'nullable|string|max:255',
            'packages.*.weight' => 'nullable|numeric|min:0.01',
            'packages.*.weight_unit' => 'nullable|string|in:kg,lb',
            'packages.*.length_cm' => 'nullable|numeric|min:0',
            'packages.*.width_cm' => 'nullable|numeric|min:0',
            'packages.*.height_cm' => 'nullable|numeric|min:0',
            'packages.*.fragile' => 'nullable|boolean',
            'packages.*.dimensions' => 'nullable|string|max:255',
            'packages.*.declared_value' => 'nullable|string|max:255',
            'packages.*.content' => 'nullable|string',
            'weight' => 'nullable|numeric|min:0.01',
            'weight_unit' => 'nullable|string|in:kg,lb',
            'dimensions' => 'nullable|string|max:255',
            'pieces' => 'nullable|string|max:255',
            'status' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $status = $data['status'] ?? null;
        $packages = array_key_exists('packages', $data) ? $data['packages'] : null;
        unset($data['status'], $data['packages']);

        if ($data) {
            $shipment->update($data);
        }

        if (is_array($packages)) {
            $this->syncPackages($shipment, $packages);
        }

        // A status change has to reach the driver route/stop as well, so it is
        // applied through the dispatch service instead of a plain update.
        if ($status !== null && $status !== $shipment->status) {
            $shipment = $this->dispatch->applyStatus($shipment, $status);
        }

        $shipment->parsed_tracking = $shipment->getParsedTracking();
        $shipment->tracking_url = $shipment->getTrackingUrl();

        return response()->json($shipment);
    }

    public function assignDriver(Request $request, Shipment $shipment): JsonResponse
    {
        $this->authorize('assignDriver', $shipment);

        $data = $request->validate([
            'driver_id' => ['nullable', 'string'],
        ]);

        $driver = null;
        if (! empty($data['driver_id'])) {
            $driver = User::whereHas('roles', fn ($query) => $query->where('name', 'driver'))
                ->where(function ($query) use ($data) {
                    $query->where('id', $data['driver_id'])
                        ->orWhereHas('driverProfile', fn ($profile) => $profile->where('driver_id', $data['driver_id']));
                })
                ->first();

            if (! $driver) {
                return response()->json(['message' => 'El conductor seleccionado no existe o no tiene el rol driver.'], 422);
            }
        }

        $shipment = $this->dispatch->assignDriver($shipment, $driver);

        $shipment->parsed_tracking = $shipment->getParsedTracking();
        $shipment->tracking_url = $shipment->getTrackingUrl();

        return response()->json([
            'message' => $driver ? 'Conductor asignado correctamente.' : 'Conductor removido correctamente.',
            'shipment' => $shipment,
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);

        $this->authorize('delete', $shipment);

        $shipment->delete();

        return response()->json(['message' => 'Envío eliminado.']);
    }

    /**
     * Persist the shipment's packages in the normalized shipment_packages
     * table and keep the packages JSON column in sync for display readers.
     */
    private function syncPackages(Shipment $shipment, array $packages): void
    {
        $shipment->packageItems()->delete();

        $normalized = [];

        foreach ($packages as $pkg) {
            if (! is_array($pkg)) {
                continue;
            }

            $length = isset($pkg['length_cm']) && is_numeric($pkg['length_cm']) ? (float) $pkg['length_cm'] : null;
            $width = isset($pkg['width_cm']) && is_numeric($pkg['width_cm']) ? (float) $pkg['width_cm'] : null;
            $height = isset($pkg['height_cm']) && is_numeric($pkg['height_cm']) ? (float) $pkg['height_cm'] : null;

            // Fallback: parse a "LxWxH cm" display string when numeric fields are absent.
            if ((! $length || ! $width) && ! empty($pkg['dimensions'])
                && preg_match('/(\d+(?:\.\d+)?)\s*[x×]\s*(\d+(?:\.\d+)?)\s*(?:[x×]\s*(\d+(?:\.\d+)?))?/i', $pkg['dimensions'], $m)) {
                $length = $length ?: (float) $m[1];
                $width = $width ?: (float) $m[2];
                $height = $height ?: (isset($m[3]) && $m[3] !== '' ? (float) $m[3] : null);
            }

            $dimValues = array_filter([$length, $width, $height], fn ($v) => $v !== null);
            $row = [
                'type' => $pkg['type'] ?? 'package',
                'pieces' => max(1, (int) ($pkg['pieces'] ?? 1)),
                'weight' => isset($pkg['weight']) && is_numeric($pkg['weight']) ? (float) $pkg['weight'] : null,
                'weight_unit' => $pkg['weight_unit'] ?? 'kg',
                'length_cm' => $length,
                'width_cm' => $width,
                'height_cm' => $height,
                'volume_m3' => ShipmentPackage::volumeM3($length, $width, $height),
                'declared_value' => isset($pkg['declared_value']) && is_numeric($pkg['declared_value']) ? (float) $pkg['declared_value'] : null,
                'content' => $pkg['content'] ?? null,
                'fragile' => filter_var($pkg['fragile'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];

            $shipment->packageItems()->create($row);

            $normalized[] = $row + [
                'dimensions' => $pkg['dimensions'] ?? ($dimValues ? implode('x', $dimValues) . ' cm' : null),
                'declared_value' => $pkg['declared_value'] ?? $row['declared_value'],
            ];
        }

        $shipment->update(['packages' => $normalized]);
    }

    /**
     * Cancel shipment (owner requests cancellation).
     */
    public function cancel(Request $request, $id)
    {
        $shipment = Shipment::find($id);

        if (!$shipment) {
            return response()->json(['success' => false, 'message' => 'not_found'], 404);
        }

        $user = $request->user();
        $isStaff = $user->hasAnyRole(['admin', 'administrative', 'finance', 'operations', 'ops_supervisor']);
        if ($shipment->user_id !== $user->id && ! $isStaff) {
            return response()->json(['success' => false, 'message' => 'forbidden'], 403);
        }

        if (in_array($shipment->status, ['delivered', 'entregado', 'entregada'], true)) {
            return response()->json(['success' => false, 'message' => 'delivered_shipment_cannot_be_cancelled'], 422);
        }
        if (in_array($shipment->status, ['cancelled', 'cancelado', 'cancel_requested'], true)) {
            return response()->json(['success' => false, 'message' => 'already_cancelled'], 409);
        }

        $preview = $this->dispatch->cancellationStage($shipment->loadMissing('manifestDetail.manifest'));
        if (! $preview['cancellable']) {
            return response()->json(['success' => false, 'message' => 'shipment_left_hub'], 422);
        }

        // Staff cancels directly with the stage fee; a client's request while
        // the shipment is moving still goes to Operations for review.
        $transit = ! $isStaff && in_array($shipment->status, ['picked_up', 'in_transit', 'transit', 'out_for_delivery'], true);

        if ($transit) {
            // En tránsito: queda como solicitud para Operations
            $shipment->update(['status' => 'cancel_requested']);
            $inc = new Incident();
            $inc->ship = $shipment->tracking_number;
            $inc->title = 'Solicitud de cancelación';
            $inc->category = 'cancel';
            $inc->severity = 'high';
            $inc->status = 'open';
            $inc->driver_id = $shipment->driver_id ?? $request->user()->id;
            $inc->code = 'INC-' . strtoupper(Str::random(6));
            $inc->save();

            if ($shipment->driver_id) {
                app(\App\Services\DriverNotificationService::class)->send(
                    $shipment->driver_id,
                    'Cancelación solicitada',
                    "El cliente solicitó cancelar el envío {$shipment->tracking_number}. Espera indicaciones de operaciones."
                );
            }
        } else {
            // Pendiente/asignado: cancelación directa
            $this->dispatch->applyStatus($shipment, 'cancelled');
        }

        return response()->json([
            'success' => true,
            'status' => $shipment->status,
            'message' => $transit ? 'cancel_requested' : 'cancelled',
        ]);
    }

    /**
     * Unreviewed returns count — badge for Operations / Finance nav.
     * Covers return statuses plus shipments carrying an open incident,
     * mirroring what the returns view lists.
     */
    public function returnsPendingCount(Request $request)
    {
        $user = $request->user();
        if (! $user->hasAnyRole(['admin', 'administrative', 'finance', 'operations', 'ops_supervisor'])) {
            return response()->json(['success' => false, 'message' => 'forbidden'], 403);
        }

        $returnStatuses = ['failed_attempt', 'returned', 'devuelto', 'incident', 'incidencia', 'cancelled', 'cancelado', 'cancel_requested'];
        $inReturns = function ($query) use ($returnStatuses) {
            $query->whereIn('status', $returnStatuses)
                ->orWhereExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('incidents')
                        ->whereIn('incidents.status', ['open', 'investigating'])
                        ->where(function ($w) {
                            $w->whereColumn('incidents.ship', 'shipments.tracking_number')
                                ->orWhereColumn('incidents.ship', 'shipments.id');
                        });
                });
        };

        // Operations queue: return cases not yet reviewed.
        $count = Shipment::whereNull('return_reviewed_at')->where($inReturns)->count();

        // Finance queue: reviewed by operations, payment/process not finished.
        $pendingProcess = Shipment::whereNotNull('return_reviewed_at')
            ->whereNull('return_processed_at')
            ->where($inReturns)
            ->count();

        return response()->json(['count' => $count, 'pending_process' => $pendingProcess]);
    }

    /**
     * Mark a returned / failed / cancelled shipment as reviewed by staff
     * (Operations marks it; finance and admin can read the status).
     */
    public function returnReview(Request $request, $id)
    {
        $shipment = Shipment::find($id);

        if (! $shipment) {
            return response()->json(['success' => false, 'message' => 'not_found'], 404);
        }

        $user = $request->user();
        if (! $user->hasAnyRole(['admin', 'operations', 'ops_supervisor'])) {
            return response()->json(['success' => false, 'message' => 'forbidden'], 403);
        }

        if (! in_array($shipment->status, ['returned', 'devuelto', 'failed_attempt', 'incident', 'incidencia', 'cancelled', 'cancelado', 'cancel_requested'], true)) {
            return response()->json(['success' => false, 'message' => 'shipment_not_returnable'], 422);
        }

        $shipment->update([
            'return_reviewed_at' => now(),
            'return_reviewed_by' => $user->id,
        ]);

        return response()->json([
            'success' => true,
            'return_reviewed_at' => $shipment->return_reviewed_at,
            'return_reviewed_by' => $shipment->return_reviewed_by,
            'return_reviewer' => ['id' => $user->id, 'name' => $user->name],
        ]);
    }

    /**
     * Finance marks a reviewed return as processed once the charge /
     * payment method has been handled.
     */
    public function returnProcess(Request $request, $id)
    {
        $shipment = Shipment::find($id);

        if (! $shipment) {
            return response()->json(['success' => false, 'message' => 'not_found'], 404);
        }

        $user = $request->user();
        if (! $user->hasAnyRole(['admin', 'finance', 'administrative'])) {
            return response()->json(['success' => false, 'message' => 'forbidden'], 403);
        }

        if (! $shipment->return_reviewed_at) {
            return response()->json(['success' => false, 'message' => 'not_reviewed'], 422);
        }

        $shipment->update([
            'return_processed_at' => now(),
            'return_processed_by' => $user->id,
        ]);

        return response()->json([
            'success' => true,
            'return_processed_at' => $shipment->return_processed_at,
            'return_processed_by' => $shipment->return_processed_by,
            'return_processor' => ['id' => $user->id, 'name' => $user->name],
        ]);
    }

    private function hasOpenIncident(Shipment $shipment): bool
    {
        return Incident::where(function ($query) use ($shipment) {
            $query->where('ship', $shipment->tracking_number)
                ->orWhere('ship', (string) $shipment->id);
        })->whereIn('status', ['open', 'investigating'])->exists();
    }

    private function withClientMeta(Shipment $shipment): void
    {
        $shipment->loadMissing('client');
        $shipment->client_type = $shipment->client?->hasAnyRole(['company', 'executive_client'])
            ? 'empresa'
            : 'persona';
        $shipment->client_name = $shipment->client?->name;
    }
}
