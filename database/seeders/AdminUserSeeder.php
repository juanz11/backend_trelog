<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $admin = User::updateOrCreate(
            ['email' => 'uraharazamora@gmail.com'],
            [
                'name' => 'Admin User',
                'email' => 'uraharazamora@gmail.com',
                'password' => Hash::make('admin123'),
            ]
        );

        $adminRole = Role::where('name', 'admin')->first();
        $operationsRole = Role::where('name', 'operations')->first();

        if ($adminRole && ! $admin->roles()->where('roles.id', $adminRole->id)->exists()) {
            $admin->roles()->attach($adminRole);
        }

        if ($operationsRole && ! $admin->roles()->where('roles.id', $operationsRole->id)->exists()) {
            $admin->roles()->attach($operationsRole);
        }
    }
}
