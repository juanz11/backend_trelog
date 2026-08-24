<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            // User Management
            ['name' => 'users.view', 'display_name' => 'Ver Usuarios', 'description' => 'Puede ver lista de usuarios', 'module' => 'users'],
            ['name' => 'users.create', 'display_name' => 'Crear Usuarios', 'description' => 'Puede crear nuevos usuarios', 'module' => 'users'],
            ['name' => 'users.edit', 'display_name' => 'Editar Usuarios', 'description' => 'Puede editar información de usuarios', 'module' => 'users'],
            ['name' => 'users.delete', 'display_name' => 'Eliminar Usuarios', 'description' => 'Puede eliminar usuarios', 'module' => 'users'],
            
            // Roles & Permissions
            ['name' => 'roles.view', 'display_name' => 'Ver Roles', 'description' => 'Puede ver lista de roles', 'module' => 'roles'],
            ['name' => 'roles.create', 'display_name' => 'Crear Roles', 'description' => 'Puede crear nuevos roles', 'module' => 'roles'],
            ['name' => 'roles.edit', 'display_name' => 'Editar Roles', 'description' => 'Puede editar roles', 'module' => 'roles'],
            ['name' => 'roles.delete', 'display_name' => 'Eliminar Roles', 'description' => 'Puede eliminar roles', 'module' => 'roles'],
            ['name' => 'permissions.view', 'display_name' => 'Ver Permisos', 'description' => 'Puede ver permisos', 'module' => 'roles'],
            ['name' => 'permissions.create', 'display_name' => 'Crear Permisos', 'description' => 'Puede crear permisos', 'module' => 'roles'],
            ['name' => 'permissions.edit', 'display_name' => 'Editar Permisos', 'description' => 'Puede editar permisos', 'module' => 'roles'],
            ['name' => 'permissions.delete', 'display_name' => 'Eliminar Permisos', 'description' => 'Puede eliminar permisos', 'module' => 'roles'],
            
            // Quotes
            ['name' => 'quotes.view', 'display_name' => 'Ver Cotizaciones', 'description' => 'Puede ver cotizaciones', 'module' => 'quotes'],
            ['name' => 'quotes.create', 'display_name' => 'Crear Cotizaciones', 'description' => 'Puede crear cotizaciones', 'module' => 'quotes'],

            // Shipments
            ['name' => 'shipments.create', 'display_name' => 'Crear Envíos', 'description' => 'Puede crear nuevos envíos', 'module' => 'shipments'],
            ['name' => 'shipments.view', 'display_name' => 'Ver Envíos', 'description' => 'Puede ver envíos', 'module' => 'shipments'],
            ['name' => 'shipments.view_own', 'display_name' => 'Ver Propios Envíos', 'description' => 'Puede ver solo sus envíos', 'module' => 'shipments'],
            ['name' => 'shipments.view_assigned', 'display_name' => 'Ver Envíos Asignados', 'description' => 'Puede ver envíos asignados', 'module' => 'shipments'],
            ['name' => 'shipments.edit', 'display_name' => 'Editar Envíos', 'description' => 'Puede editar envíos', 'module' => 'shipments'],
            ['name' => 'shipments.delete', 'display_name' => 'Eliminar Envíos', 'description' => 'Puede eliminar envíos', 'module' => 'shipments'],
            
            // Dispatch
            ['name' => 'dispatch.manage', 'display_name' => 'Gestionar Despacho', 'description' => 'Puede gestionar operaciones de despacho', 'module' => 'dispatch'],
            ['name' => 'drivers.manage', 'display_name' => 'Gestionar Conductores', 'description' => 'Puede gestionar conductores', 'module' => 'dispatch'],
            
            // Reports
            ['name' => 'reports.view_own', 'display_name' => 'Ver Propios Reportes', 'description' => 'Puede ver sus propios reportes', 'module' => 'reports'],
            ['name' => 'reports.view_ops', 'display_name' => 'Ver Reportes Operativos', 'description' => 'Puede ver reportes operativos', 'module' => 'reports'],
            ['name' => 'reports.view_full', 'display_name' => 'Ver Reportes Completos', 'description' => 'Puede ver todos los reportes', 'module' => 'reports'],
            
            // Pricing
            ['name' => 'pricing.view', 'display_name' => 'Ver Precios', 'description' => 'Puede ver reglas de precios', 'module' => 'pricing'],
            ['name' => 'pricing.edit', 'display_name' => 'Editar Precios', 'description' => 'Puede editar reglas de precios', 'module' => 'pricing'],
            ['name' => 'pricing.edit_limited', 'display_name' => 'Editar Precios (Limitado)', 'description' => 'Puede editar algunas reglas de precios', 'module' => 'pricing'],
            
            // Audit
            ['name' => 'audit.view', 'display_name' => 'Ver Registros de Auditoría', 'description' => 'Puede ver registros de auditoría', 'module' => 'audit'],
            ['name' => 'audit.view_limited', 'display_name' => 'Ver Registros de Auditoría (Limitado)', 'description' => 'Puede ver registros limitados de auditoría', 'module' => 'audit'],
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission['name']],
                [
                    'display_name' => $permission['display_name'],
                    'description' => $permission['description'],
                    'module' => $permission['module'],
                ]
            );
        }
    }
}
