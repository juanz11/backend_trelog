<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Permission;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $customerRole = Role::where('name', 'customer')->first();
        $driverRole = Role::where('name', 'driver')->first();
        $operationsRole = Role::where('name', 'operations')->first();
        $adminRole = Role::where('name', 'admin')->first();

        if (!$customerRole || !$driverRole || !$adminRole) {
            $this->command->warn('Required roles not found. Please run RoleSeeder first.');
            return;
        }

        // Define role permissions based on the original matrix
        $rolePermissions = [
            'customer' => [
                'shipments.create',
                'shipments.view_own',
            ],
            'driver' => [
                'shipments.view_assigned',
            ],
            'executive_client' => [
                'shipments.create',
                'shipments.view_own',
                'quotes.view',
            ],
            'operations' => [
                'shipments.create',
                'shipments.view',
                'shipments.edit',
                'shipments.delete',
                'quotes.view',
                'quotes.edit',
                'dispatch.manage',
                'drivers.manage',
                'reports.view_ops',
                'audit.view_limited',
                'support.view',
                'support.edit',
                'zones.manage',
                'incidents.view',
                'incidents.edit',
                'hub.view',
            ],
            'administrative' => [
                'users.view',
                'users.block',
            ],
            'dispatcher' => [
                'shipments.create',
                'shipments.view',
                'shipments.edit',
                'quotes.view',
                'dispatch.manage',
                'drivers.manage',
                'reports.view_ops',
            ],
            'manager' => [
                'users.view',
                'users.create',
                'users.edit',
                'shipments.create',
                'shipments.view',
                'shipments.edit',
                'quotes.view',
                'dispatch.manage',
                'drivers.manage',
                'reports.view_full',
                'pricing.view',
                'pricing.edit_limited',
                'audit.view_limited',
            ],
            'executive_client' => [
                'shipments.create',
                'shipments.view',
                'shipments.view_own',
                'quotes.view',
                'quotes.create',
                'pricing.view',
                'support.view',
                'support.edit',
            ],
            'customer_support' => [
                'incidents.view',
                'incidents.edit',
                'support.view',
                'support.edit',
                'shipments.view',
                'shipments.edit',
            ],
            'ops_supervisor' => [
                'users.view',
                'shipments.create',
                'shipments.view',
                'shipments.edit',
                'shipments.delete',
                'quotes.view',
                'quotes.edit',
                'dispatch.manage',
                'drivers.manage',
                'reports.view_full',
                'pricing.view',
                'pricing.edit_limited',
                'audit.view_limited',
                'support.view',
                'support.edit',
                'zones.manage',
                'incidents.view',
                'incidents.edit',
            ],
            'hub_operation' => [
                'shipments.view',
                'shipments.edit',
                'dispatch.manage',
                'hub.view',
                'reports.view_ops',
            ],
            'hub_supervisor' => [
                'shipments.view',
                'shipments.edit',
                'shipments.delete',
                'dispatch.manage',
                'drivers.manage',
                'hub.view',
                'hub.manage',
                'reports.view_ops',
                'incidents.view',
                'incidents.edit',
            ],
            'finance' => [
                'reports.view_full',
                'audit.view',
                'finance.view',
                'finance.edit',
                'pricing.view',
                'users.view',
            ],
            'admin' => [
                'users.view',
                'users.create',
                'users.edit',
                'users.delete',
                'roles.view',
                'roles.create',
                'roles.edit',
                'roles.delete',
                'permissions.view',
                'permissions.create',
                'permissions.edit',
                'permissions.delete',
                'shipments.create',
                'shipments.view',
                'shipments.edit',
                'shipments.delete',
                'quotes.view',
                'quotes.edit',
                'quotes.delete',
                'dispatch.manage',
                'drivers.manage',
                'reports.view_full',
                'pricing.view',
                'pricing.edit',
                'audit.view',
                'support.view',
                'support.edit',
                'zones.manage',
                'incidents.view',
                'incidents.edit',
                'finance.view',
                'finance.edit',
                'hub.view',
                'hub.manage',
            ],
        ];

        foreach ($rolePermissions as $roleName => $permissionNames) {
            $role = Role::where('name', $roleName)->first();
            if (!$role) continue;

            foreach ($permissionNames as $permissionName) {
                $permission = Permission::where('name', $permissionName)->first();
                if ($permission) {
                    $role->givePermissionTo($permission);
                }
            }
        }

        $this->command->info('Role permissions seeded successfully.');
    }
}
