<?php

namespace App\Services;

use App\Models\DeliveryRoute;
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
    public function assignDriver(Shipment $shipment, ?User $driver): Shipment
    {
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

        $pickedUp = in_array($status, ['picked_up', 'in_transit', 'transit', 'incident', 'incidencia'], true)
            || ($manifest && $manifest->hub_received_at);

        $percent = $pickedUp ? $policy['recobro_percent'] : $policy['percent'];

        return [
            'cancellable' => true,
            'percent' => $percent,
            'fee' => is_numeric($shipment->amount) ? round(((float) $shipment->amount) * $percent / 100, 2) : null,
            'stage' => $pickedUp ? 'picked_up_or_hub' : 'not_picked_up',
        ];
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
