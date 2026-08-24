<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        Gate::before(function ($user, $ability) {
            // Grant admins full access to all authorization checks.
            return $user?->isAdmin() ? true : null;
        });
    }
}
