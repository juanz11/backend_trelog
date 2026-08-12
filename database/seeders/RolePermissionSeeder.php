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
        $dispatcherRole = Role::where('name', 'dispatcher')->first();
        $managerRole = Role::where('name', 'manager')->first();
        $adminRole = Role::where('name', 'admin')->first();

        if (!$customerRole || !$driverRole || !$dispatcherRole || !$managerRole || !$adminRole) {
            $this->command->warn('Roles not found. Please run RoleSeeder first.');
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
            'dispatcher' => [
                'shipments.create',
                'shipments.view',
                'shipments.edit',
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
                'dispatch.manage',
                'drivers.manage',
                'reports.view_full',
                'pricing.view',
                'pricing.edit_limited',
                'audit.view_limited',
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
                'dispatch.manage',
                'drivers.manage',
                'reports.view_full',
                'pricing.view',
                'pricing.edit',
                'audit.view',
            ],
        ];

        foreach ($rolePermissions as $roleName => $permissionNames) {
            $role = Role::where('name', $roleName)->first();
            if (!$role) continue;

            foreach ($permissionNames as $permissionName) {
                $permission = Permission::where('name', $permissionName)->first();
                if ($permission) {
                    $role->permissions()->syncWithoutDetaching([$permission->id]);
                }
            }
        }

        $this->command->info('Role permissions seeded successfully.');
    }
}
