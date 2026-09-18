<?php

namespace App\Services;

use App\Models\DeliveryRoute;
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
    public const CLOSED_STATUSES = ['delivered', 'cancelled'];

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
        DB::transaction(function () use ($shipment, $status, $notes) {
            $shipment->update(['status' => $status]);

            $driver = $shipment->driver;
            if (! $driver) {
                return;
            }

            $stop = $shipment->stop ?? $this->syncStop($shipment, $driver);
            $stop->update(['state' => $this->stopStateFor($status)]);

            $this->log($shipment, $notes ?? $shipment->destination, $stop->route_id);
            $this->refreshRouteProgress($stop->route_id);
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
    }

    public function stopStateFor(string $shipmentStatus): string
    {
        return match ($shipmentStatus) {
            'delivered' => 'Done',
            'incident' => 'Failed',
            'in_transit', 'out_for_delivery' => 'Next',
            default => 'Pending',
        };
    }

    private function activeRouteFor(User $driver): DeliveryRoute
    {
        $route = DeliveryRoute::where('driver_id', $driver->id)
            ->whereIn('status', ['In progress', 'Pending'])
            // CASE y no FIELD(): FIELD es solo de MySQL y la suite corre en SQLite.
            // Mismo orden: primero la ruta en curso, despues la pendiente.
            ->orderByRaw("CASE status WHEN 'In progress' THEN 0 WHEN 'Pending' THEN 1 ELSE 2 END")
            ->latest()
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
}
