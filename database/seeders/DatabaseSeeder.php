<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Sin usuarios de prueba con contraseña ni roles locales (Lote 9): las
        // personas y sus roles viven en el SSO. Lo que se siembra es el dominio.
        $this->call([
            ZoneSeeder::class,
            DriverSeeder::class,
        ]);
    }
}
