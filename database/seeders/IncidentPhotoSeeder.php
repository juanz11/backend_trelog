<?php

namespace Database\Seeders;

use App\Models\Incident;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class IncidentPhotoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $driver = User::updateOrCreate(
            ['email' => 'driver.demo@tr3slog.demo'],
            [
                'name' => 'Conductor Demo',
                'email' => 'driver.demo@tr3slog.demo',
                'password' => Hash::make('Tr3slog2024'),
            ]
        );

        $driverRole = Role::where('name', 'driver')->first();
        if ($driverRole) {
            $driver->assignRole($driverRole);
        }

        Incident::updateOrCreate(
            ['code' => 'INC-DEMO-001'],
            [
                'driver_id' => $driver->id,
                'category' => 'Damage',
                'title' => 'Paquete dañado en tránsito',
                'ship' => 'TR3-DEMO-001',
                'severity' => 'high',
                'status' => 'open',
                'description' => 'Incidente de demostración con foto adjunta para validar visualización en Operaciones.',
                'photo_path' => 'incident-photos/incident-proof.webp',
            ]
        );

        $this->command->info('Incidente demo con foto creado/actualizado.');
    }
}
