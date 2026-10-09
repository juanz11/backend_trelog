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
                'display_name' => 'Administrador del sistema',
                'description' => 'Acceso total: usuarios, roles, integraciones, funciones/despliegue, llaves API y mantenimiento.',
            ],
            [
                'name' => 'operations',
                'display_name' => 'Operaciones',
                'description' => 'Equipo de operaciones que gestiona envíos y conductores',
            ],
            [
                'name' => 'administrative',
                'display_name' => 'Administrativo',
                'description' => 'Personal administrativo que puede ver usuarios y bloquearlos',
            ],
            [
                'name' => 'driver',
                'display_name' => 'Conductor',
                'description' => 'Conductor que puede gestionar envíos asignados',
            ],
            [
                'name' => 'executive_client',
                'display_name' => 'Cliente Ejecutivo (B2B / Contrato)',
                'description' => 'Cliente B2B con acceso a cotizaciones, tarifas y envíos corporativos',
            ],
            [
                'name' => 'customer_support',
                'display_name' => 'Customer Support / Atención al Cliente',
                'description' => 'Gestiona incidencias y tickets de atención al cliente',
            ],
            [
                'name' => 'ops_supervisor',
                'display_name' => 'Operations Supervisor',
                'description' => 'Supervisa operaciones, envíos, conductores y despacho',
            ],
            [
                'name' => 'hub_operation',
                'display_name' => 'Hub Operation (Almacén / Cross-Dock)',
                'description' => 'Opera almacén y cross-dock: recibe, clasifica y despacha carga',
            ],
            [
                'name' => 'hub_supervisor',
                'display_name' => 'Hub Supervisor',
                'description' => 'Supervisa operaciones de hub, almacén y cross-dock',
            ],
            [
                'name' => 'finance',
                'display_name' => 'Finanzas / Facturación',
                'description' => 'Gestiona facturación, pagos y reportes financieros',
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
