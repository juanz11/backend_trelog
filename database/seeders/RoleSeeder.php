<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            [
                'name' => 'customer',
                'display_name' => 'Cliente',
                'description' => 'Cliente regular que puede crear y rastrear envíos',
            ],
            [
                'name' => 'driver',
                'display_name' => 'Conductor',
                'description' => 'Conductor que puede gestionar envíos asignados',
            ],
            [
                'name' => 'dispatcher',
                'display_name' => 'Despachador',
                'description' => 'Despachador que puede gestionar envíos y conductores',
            ],
            [
                'name' => 'manager',
                'display_name' => 'Gerente',
                'description' => 'Gerente con permisos administrativos limitados',
            ],
            [
                'name' => 'admin',
                'display_name' => 'Administrador',
                'description' => 'Administrador del sistema con todos los permisos',
            ],
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(
                ['name' => $role['name']],
                [
                    'display_name' => $role['display_name'],
                    'description' => $role['description'],
                ]
            );
        }
    }
}
