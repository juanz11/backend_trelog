<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class MigrateUserRolesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = User::all();
        
        foreach ($users as $user) {
            if ($user->role) {
                // Find the role by name
                $role = Role::where('name', $user->role)->first();
                
                if ($role) {
                    // Attach the role to the user
                    $user->roles()->attach($role->id);
                    $this->command->info("Migrated user {$user->email} with role {$user->role}");
                } else {
                    $this->command->warn("Role '{$user->role}' not found for user {$user->email}");
                }
            }
        }
        
        $this->command->info('User roles migration completed.');
    }
}
