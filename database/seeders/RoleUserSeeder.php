<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RoleUserSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = [
            [
                'name' => 'Cliente Ejecutivo',
                'email' => 'ejecutivo@tr3slog.demo',
                'role' => 'executive_client',
                'password' => 'Tr3slog2024',
            ],
            [
                'name' => 'Atención al Cliente',
                'email' => 'soporte@tr3slog.demo',
                'role' => 'customer_support',
                'password' => 'Tr3slog2024',
            ],
            [
                'name' => 'Operations Supervisor',
                'email' => 'ops.supervisor@tr3slog.demo',
                'role' => 'ops_supervisor',
                'password' => 'Tr3slog2024',
            ],
            [
                'name' => 'Hub Operation',
                'email' => 'hub.op@tr3slog.demo',
                'role' => 'hub_operation',
                'password' => 'Tr3slog2024',
            ],
            [
                'name' => 'Hub Supervisor',
                'email' => 'hub.supervisor@tr3slog.demo',
                'role' => 'hub_supervisor',
                'password' => 'Tr3slog2024',
            ],
            [
                'name' => 'Finanzas y Facturación',
                'email' => 'finance@tr3slog.demo',
                'role' => 'finance',
                'password' => 'Tr3slog2024',
            ],
        ];

        foreach ($users as $demo) {
            $user = User::updateOrCreate(
                ['email' => $demo['email']],
                [
                    'name' => $demo['name'],
                    'email' => $demo['email'],
                    'password' => Hash::make($demo['password']),
                ]
            );

            $role = Role::where('name', $demo['role'])->first();

            if ($role) {
                $user->assignRole($role);
            } else {
                $this->command->warn("Role {$demo['role']} not found. Run RoleSeeder first.");
            }
        }

        $this->command->info('Demo users for new roles created/updated successfully.');
    }
}
