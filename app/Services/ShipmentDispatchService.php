<?php

namespace App\Services;

use App\Exceptions\ManifestException;
use App\Models\DeliveryRoute;
use App\Models\Manifest;
use App\Models\ManifestDetail;
use App\Models\PricingConfig;
use App\Models\RouteAuditLog;
use App\Models\RouteStop;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Keeps shipments, driver routes and route stops in sync no matter where the
 * change comes from (operations panel or driver app).
 */
class ShipmentDispatchService
{
    /**
     * Statuses that take a shipment out of the driver's active workload.
     */
    public const CLOSED_STATUSES = ['delivered', 'cancelled', 'returned'];

    /**
     * Statuses that count as a shipment cancellation (ES + EN variants).
     */
    public const CANCELLED_STATUSES = ['cancelled', 'cancelado'];

    /**
     * Assign (or unassign) a driver and keep the driver route in sync.
     */
    /**
     * Manifest statuses where the shipment is locked to its manifest driver.
     */
    private const OPEN_MANIFEST_STATUSES = [
        Manifest::STATUS_CREADO,
        Manifest::STATUS_ASIGNADO,
        Manifest::STATUS_EN_RUTA,
        Manifest::STATUS_EN_SOLICITUD_TRASPASO,
    ];

    /**
     * @param bool $internal  true cuando el cambio viene del propio ciclo de
     *                        vida del manifiesto (finalize, traspaso, rechazo)
     *                        — esos flujos sí pueden tocar sus envíos.
     */
    public function assignDriver(Shipment $shipment, ?User $driver, bool $internal = false): Shipment
    {
        // Sincronía: un envío dentro de un manifiesto abierto pertenece al
        // chofer del manifiesto — no se puede reasignar por el otro canal.
        $manifest = $shipment->manifestDetail?->manifest;
        if (
            ! $internal
            && $manifest
            && in_array($manifest->status, self::OPEN_MANIFEST_STATUSES, true)
            && $manifest->driver_id !== $driver?->id
        ) {
            throw new ManifestException(
                'EN_MANIFIESTO',
                "El envío pertenece al manifiesto {$manifest->code}. Reasígnelo desde el manifiesto."
            );
        }

        DB::transaction(function () use ($shipment, $driver) {
            $shipment->update([
                'driver_id' => $driver?->id,
                'assigned_at' => $driver ? now() : null,
                'status' => $this->statusAfterAssignment($shipment, $driver),
            ]);

            if ($driver) {
                $this->syncStop($shipment, $driver);
                $this->log($shipment, "asignado a {$driver->name}");
                $this->notify(
                    $driver->id,
                    'Nuevo envío asignado',
                    "Se te asignó el envío {$shipment->tracking_number}".
                        ($shipment->destination ? " hacia {$shipment->destination}." : '.')
                );
            } else {
                $this->detachStop($shipment);
            }
        });

        return $shipment->fresh(['driver.driverProfile', 'stop']);
    }

    /**
     * Apply a status change and propagate it to the driver's stop and route.
     */
    public function applyStatus(Shipment $shipment, string $status, ?string $notes = null): Shipment
    {
        $previous = (string) $shipment->status;

        DB::transaction(function () use ($shipment, $status, $notes, $previous) {
            // Cancellation fee fields ride along with the status update so the
            // recobro email already sees the charged amount.
            $shipment->update(array_merge(
                ['status' => $status],
                $this->cancellationFeeAttributes($shipment, $previous, $status)
            ));

            $driver = $shipment->driver;
            if (! $driver) {
                return;
            }

            $stop = $shipment->stop ?? $this->syncStop($shipment, $driver);
            $stop->update(['state' => $this->stopStateFor($status)]);

            $this->log($shipment, $notes ?? $shipment->destination, $stop->route_id);
            $this->refreshRouteProgress($stop->route_id);

            if (in_array($status, self::CANCELLED_STATUSES, true)) {
                $this->notify(
                    $driver->id,
                    'Envío cancelado',
                    "El envío {$shipment->tracking_number} fue cancelado y salió de tu ruta."
                );
            }

            // Sincronía: si el envío está en un manifiesto, su parada del
            // manifiesto refleja el cambio hecho desde operaciones.
            $detail = $shipment->manifestDetail;
            if ($detail && $detail->status === ManifestDetail::STATUS_PENDIENTE) {
                $detailStatus = match (true) {
                    in_array($status, ['delivered', 'entregado', 'entregada'], true)
                        => ManifestDetail::STATUS_ENTREGADO,
                    in_array(
                        $status,
                        [...self::CANCELLED_STATUSES, 'incident', 'incidencia'],
                        true
                    ) => ManifestDetail::STATUS_NO_ENTREGADO,
                    default => null,
                };
                if ($detailStatus) {
                    $detail->update([
                        'status' => $detailStatus,
                        'failure_reason' => $detailStatus === ManifestDetail::STATUS_NO_ENTREGADO
                            ? ($notes ?? 'Marcado por operaciones')
                            : null,
                        'visited_at' => now(),
                    ]);
                    // Si era la última pendiente, el manifiesto se cierra solo.
                    app(ManifestService::class)
                        ->autoCloseIfResolved($detail->manifest->fresh());
                }
            }
        });

        return $shipment->fresh(['driver.driverProfile', 'stop']);
    }

    /**
     * Ensure the shipment is represented as a stop on the driver's active route.
     */
    public function syncStop(Shipment $shipment, User $driver): RouteStop
    {
        $route = $this->activeRouteFor($driver);

        if ($stop = $shipment->stop) {
            if ($stop->route_id !== $route->id) {
                $previousRouteId = $stop->route_id;
                $stop->update([
                    'route_id' => $route->id,
                    'n' => (int) RouteStop::where('route_id', $route->id)->max('n') + 1,
                    'state' => $this->stopStateFor($shipment->status),
                ]);
                $this->refreshRouteProgress($previousRouteId);
                $this->refreshRouteProgress($route->id);
            }

            return $stop;
        }

        $stop = RouteStop::create([
            'route_id' => $route->id,
            'shipment_id' => $shipment->id,
            'n' => (int) RouteStop::where('route_id', $route->id)->max('n') + 1,
            'name' => $shipment->recipient_name ?: ($shipment->tracking_number ?: "Envío #{$shipment->id}"),
            'addr' => $shipment->destination ?: '—',
            'type' => 'Delivery',
            'state' => $this->stopStateFor($shipment->status),
        ]);

        $this->refreshRouteProgress($route->id);

        $shipment->setRelation('stop', $stop);

        return $stop;
    }

    public function refreshRouteProgress(int $routeId): void
    {
        $stops = RouteStop::where('route_id', $routeId)->get();
        $done = $stops->where('state', 'Done')->count();

        DeliveryRoute::where('id', $routeId)->update([
            'stops_count' => $stops->count(),
            'progress' => $stops->isEmpty() ? 0 : round($done / $stops->count(), 2),
        ]);

        // Once the driver starts working on a stop, the route is underway.
        $started = $stops->contains(
            fn ($stop) => in_array($stop->state, ['Next', 'Done', 'Failed', 'Delayed'], true)
        );
        if ($started) {
            DeliveryRoute::where('id', $routeId)
                ->where('status', 'Pending')
                ->update(['status' => 'In progress']);
        }
    }

    public function stopStateFor(string $shipmentStatus): string
    {
        return match ($shipmentStatus) {
            'delivered' => 'Done',
            'incident', 'cancelled', 'cancelado' => 'Failed',
            'in_transit', 'out_for_delivery' => 'Next',
            default => 'Pending',
        };
    }

    private function activeRouteFor(User $driver): DeliveryRoute
    {
        $route = DeliveryRoute::where('driver_id', $driver->id)
            ->whereIn('status', ['In progress', 'Pending'])
            ->orderByRaw("CASE status WHEN 'In progress' THEN 0 WHEN 'Pending' THEN 1 ELSE 2 END")
            ->oldest()
            ->first();

        if ($route) {
            return $route;
        }

        return DeliveryRoute::create([
            'driver_id' => $driver->id,
            'code' => 'RT-' . now()->format('dmy') . '-' . strtoupper(substr(md5((string) $driver->id), 0, 2)),
            'date_label' => ucfirst(now()->translatedFormat('l, j \d\e F')),
            'status' => 'Pending',
            'vehicle' => $driver->driverProfile?->vehicle,
        ]);
    }

    /**
     * Stage-based cancellation fee preview (admin/finance views). 5% while
     * nobody picked it up, 30% once picked up or received at the hub, and
     * not cancellable once it left the hub or reached a terminal state.
     * Percentages come from the pricing configuration — never hardcoded.
     */
    public function cancellationStage(Shipment $shipment): array
    {
        $policy = PricingConfig::current()->cancellationPolicy();
        $status = (string) $shipment->status;
        $manifest = $shipment->manifestDetail?->manifest;

        if (in_array($status, ['cancelled', 'cancelado', 'cancel_requested', 'delivered', 'entregado', 'entregada', 'returned', 'devuelto'], true)) {
            return ['cancellable' => false, 'percent' => null, 'fee' => null, 'stage' => 'closed'];
        }

        if ($status === 'out_for_delivery' || ($manifest && $manifest->hub_dispatched_at)) {
            return ['cancellable' => false, 'percent' => null, 'fee' => null, 'stage' => 'left_hub'];
        }

        $inHub = $status === 'in_hub' || ($manifest && $manifest->hub_received_at);
        $pickedUp = $inHub
            || in_array($status, ['picked_up', 'in_transit', 'transit', 'incident', 'incidencia'], true);

        $percent = ! $pickedUp
            ? $policy['percent']
            : ($inHub ? $policy['recobro_percent'] : $policy['picked_up_percent']);

        return [
            'cancellable' => true,
            'percent' => $percent,
            'fee' => is_numeric($shipment->amount) ? round(((float) $shipment->amount) * $percent / 100, 2) : null,
            'stage' => ! $pickedUp ? 'not_picked_up' : ($inHub ? 'in_hub' : 'picked_up'),
        ];
    }

    /**
     * Recovery (recobro) charge shown in the returns module. For cancelled
     * shipments the charged fee is reused; for rejected/returned/failed
     * shipments the recobro percent applies — the package was already picked
     * up when the client rejected it.
     */
    public function returnRecobro(Shipment $shipment): ?array
    {
        $status = (string) $shipment->status;

        if (! in_array($status, ['returned', 'devuelto', 'failed_attempt', 'incident', 'incidencia', 'cancelled', 'cancelado', 'cancel_requested'], true)) {
            return null;
        }

        $percent = $shipment->cancel_fee_percent !== null
            ? (float) $shipment->cancel_fee_percent
            : null;
        $fee = $shipment->cancel_fee !== null ? (float) $shipment->cancel_fee : null;

        if ($percent === null) {
            $policy = PricingConfig::current()->cancellationPolicy();

            // Pickup statuses imply the driver already collected the package.
            // For cancelled shipments the status is gone, so the status
            // history and the manifest hub reception tell how far it got.
            $movingStatuses = ['picked_up', 'in_transit', 'transit', 'in_hub', 'out_for_delivery', 'failed_attempt', 'incident', 'incidencia', 'returned', 'devuelto'];
            $manifest = $shipment->manifestDetail?->manifest;
            $history = collect($shipment->status_history ?? []);
            $inHub = $status === 'in_hub'
                || ($manifest && $manifest->hub_received_at)
                || $history->contains(fn ($event) => ($event['status'] ?? null) === 'in_hub');
            $pickedUp = $inHub
                || in_array($status, $movingStatuses, true)
                || $history->contains(fn ($event) => in_array($event['status'] ?? null, $movingStatuses, true));

            $percent = ! $pickedUp
                ? $policy['percent']
                : ($inHub ? $policy['recobro_percent'] : $policy['picked_up_percent']);
            $fee = is_numeric($shipment->amount)
                ? round(((float) $shipment->amount) * $percent / 100, 2)
                : null;
        }

        return ['percent' => $percent, 'fee' => $fee];
    }

    /**
     * Cancellation / late-cancellation (recobro) fee attributes for a status
     * transition. Percentages always come from the admin pricing
     * configuration — never hardcoded.
     */
    private function cancellationFeeAttributes(Shipment $shipment, string $previous, string $status): array
    {
        $wasCancelled = in_array($previous, self::CANCELLED_STATUSES, true);
        $isCancelled = in_array($status, self::CANCELLED_STATUSES, true);

        if ($isCancelled && ! $wasCancelled && $shipment->cancelled_at === null) {
            $percent = $this->cancellationStage($shipment)['percent']
                ?? PricingConfig::current()->cancellationPolicy()['recobro_percent'];

            return [
                'cancelled_at' => now(),
                'cancel_fee_percent' => $percent,
                'cancel_fee' => is_numeric($shipment->amount)
                    ? round(((float) $shipment->amount) * $percent / 100, 2)
                    : null,
            ];
        }

        if ($wasCancelled && ! $isCancelled) {
            return [
                'cancelled_at' => null,
                'cancel_fee' => null,
                'cancel_fee_percent' => null,
            ];
        }

        return [];
    }

    /**
     * Keep the shipment status consistent with its assignment state.
     */
    private function statusAfterAssignment(Shipment $shipment, ?User $driver): string
    {
        $status = (string) $shipment->status;

        if ($driver) {
            return $status === 'pending' ? 'assigned' : $status;
        }

        return $status === 'assigned' ? 'pending' : $status;
    }

    /**
     * Remove the stop of a shipment that is no longer assigned, as long as the
     * driver has not started working on it.
     */
    private function detachStop(Shipment $shipment): void
    {
        $stop = $shipment->stop;
        if (! $stop || $stop->state !== 'Pending') {
            return;
        }

        $routeId = $stop->route_id;
        $stop->delete();
        $shipment->setRelation('stop', null);
        $this->refreshRouteProgress($routeId);
    }

    private function log(Shipment $shipment, ?string $meta, ?int $routeId = null): void
    {
        $routeId ??= $shipment->stop?->route_id;
        if (! $routeId) {
            return;
        }

        RouteAuditLog::create([
            'route_id' => $routeId,
            'title' => "Envío {$shipment->tracking_number} · {$shipment->status}",
            'meta' => $meta,
        ]);
    }

    /** In-app notification + FCM push for the driver. */
    private function notify(int $driverId, string $title, string $body): void
    {
        app(DriverNotificationService::class)->send($driverId, $title, $body);
    }
}
