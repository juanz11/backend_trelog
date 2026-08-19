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
                'name' => 'company',
                'display_name' => 'Empresa',
                'description' => 'Empresa con múltiples envíos y usuarios asociados',
            ],
            [
                'name' => 'admin',
                'display_name' => 'Administrador',
                'description' => 'Administrador del sistema con todos los permisos',
            ],
            [
                'name' => 'operations',
                'display_name' => 'Operaciones',
                'description' => 'Equipo de operaciones que gestiona envíos y conductores',
            ],
            [
                'name' => 'driver',
                'display_name' => 'Conductor',
                'description' => 'Conductor que puede gestionar envíos asignados',
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
