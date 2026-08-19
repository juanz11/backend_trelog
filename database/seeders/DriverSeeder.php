<?php

namespace Database\Seeders;

use App\Models\DeliveryRoute;
use App\Models\DriverAlert;
use App\Models\DriverProfile;
use App\Models\Incident;
use App\Models\PayrollPeriod;
use App\Models\Role;
use App\Models\RouteAuditLog;
use App\Models\RouteStop;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DriverSeeder extends Seeder
{
    public function run(): void
    {
        $driver = User::firstOrCreate(
            ['email' => 'driver@tr3slog.com'],
            [
                'name' => 'E. Rivera',
                'phone' => '787-555-0110',
                'password' => Hash::make('password'),
            ]
        );

        $driverRole = Role::where('name', 'driver')->first();

        if ($driverRole && ! $driver->roles()->where('roles.id', $driverRole->id)->exists()) {
            $driver->roles()->attach($driverRole);
        }

        DriverProfile::updateOrCreate(
            ['user_id' => $driver->id],
            ['initials' => 'ER', 'vehicle' => 'Van 04 · PR-8842', 'hub' => 'Hub San Juan', 'available' => true]
        );

        DriverAlert::query()->where('driver_id', $driver->id)->delete();
        DriverAlert::create(['driver_id' => $driver->id, 'title' => 'Parada 13 retrasada', 'body' => 'Ventana de entrega vencida hace 22 min.']);
        DriverAlert::create(['driver_id' => $driver->id, 'title' => 'Incidencia abierta CS-0231', 'body' => 'Mercancía dañada · esperando instrucciones.']);

        $route = DeliveryRoute::updateOrCreate(
            ['driver_id' => $driver->id, 'code' => 'RT-2407A'],
            [
                'date_label' => 'Today · 7:30 AM',
                'stops_count' => 4,
                'duration' => '6h 20m',
                'vehicle' => 'Van 24',
                'status' => 'In progress',
                'progress' => 0.42,
                'instructions' => 'Complete all deliveries in order. Pickups require ID verification and package photo.',
            ]
        );

        RouteStop::where('route_id', $route->id)->delete();
        $stops = [
            ['n' => 1, 'name' => 'Ocean Drive Pharmacy', 'addr' => '1245 Ocean Dr, Miami Beach', 'type' => 'Delivery', 'eta' => '9:05 AM', 'state' => 'Done'],
            ['n' => 2, 'name' => 'Bayside Medical', 'addr' => '401 Biscayne Blvd, Miami', 'type' => 'Pickup', 'eta' => '9:45 AM', 'state' => 'Done'],
            ['n' => 3, 'name' => 'Coral Gables Clinic', 'addr' => '670 Coral Way, Coral Gables', 'type' => 'Delivery', 'eta' => '10:30 AM', 'state' => 'Next'],
            ['n' => 4, 'name' => 'Downtown Labs', 'addr' => '50 NE 1st Ave, Miami', 'type' => 'Delivery', 'eta' => '11:10 AM', 'state' => 'Pending'],
        ];
        foreach ($stops as $stop) {
            RouteStop::create(['route_id' => $route->id, ...$stop]);
        }

        RouteAuditLog::where('route_id', $route->id)->delete();
        RouteAuditLog::create(['route_id' => $route->id, 'title' => 'Route started', 'meta' => '7:32 AM · Miami Hub']);
        RouteAuditLog::create(['route_id' => $route->id, 'title' => 'Stop 1 completed', 'meta' => '8:47 AM · Ocean Drive Pharmacy']);
        RouteAuditLog::create(['route_id' => $route->id, 'title' => 'Stop 2 completed', 'meta' => '9:41 AM · Bayside Medical']);

        DeliveryRoute::updateOrCreate(
            ['driver_id' => $driver->id, 'code' => 'RT-2406B'],
            ['date_label' => 'Yesterday · 6:45 AM', 'stops_count' => 9, 'duration' => '5h 10m', 'vehicle' => 'Van 24', 'status' => 'Completed', 'progress' => 1.0]
        );
        DeliveryRoute::updateOrCreate(
            ['driver_id' => $driver->id, 'code' => 'RT-2405C'],
            ['date_label' => 'Jul 28 · 7:00 AM', 'stops_count' => 15, 'duration' => '7h 05m', 'vehicle' => 'Van 24', 'status' => 'Completed', 'progress' => 1.0]
        );

        Incident::updateOrCreate(
            ['driver_id' => $driver->id, 'code' => 'INC-4412'],
            [
                'category' => 'Damage',
                'title' => 'Package damaged in transit',
                'ship' => 'SHP-99821',
                'severity' => 'Medium',
                'status' => 'Open',
                'description' => null,
            ]
        );

        PayrollPeriod::updateOrCreate(
            ['driver_id' => $driver->id, 'period_label' => 'Jul 15-31'],
            ['status' => 'Pending', 'base' => 1120.00, 'bonuses' => 80.00, 'deductions' => 20.00, 'paid_on' => null]
        );
        PayrollPeriod::updateOrCreate(
            ['driver_id' => $driver->id, 'period_label' => 'Jul 1-14'],
            ['status' => 'Paid', 'base' => 1040.00, 'bonuses' => 0, 'deductions' => 0, 'paid_on' => 'Jul 16']
        );
    }
}
