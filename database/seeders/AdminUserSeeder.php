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

        $newAdmin = User::updateOrCreate(
            ['email' => 'joshuamoises1995@gmail.com'],
            [
                'name' => 'Joshua Moises',
                'email' => 'joshuamoises1995@gmail.com',
                'password' => Hash::make('2j4o3s4h'),
            ]
        );

        $adminRole = Role::where('name', 'admin')->first();
        $operationsRole = Role::where('name', 'operations')->first();

        if ($adminRole) {
            $newAdmin->assignRole($adminRole);
            $admin->assignRole($adminRole);
        }

        if ($operationsRole) {
            $newAdmin->assignRole($operationsRole);
            $admin->assignRole($operationsRole);
        }
    }
}
