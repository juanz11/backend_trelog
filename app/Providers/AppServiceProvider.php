<?php

namespace App\Providers;

use App\Models\Quote;
use App\Models\Shipment;
use App\Models\SupportTicket;
use App\Models\User;
use App\Policies\QuotePolicy;
use App\Policies\ShipmentPolicy;
use App\Policies\SupportTicketPolicy;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Cupo del tracking publico (routes/api.php): el codigo de cotizacion es
        // secuencial por dia y hub, asi que esta ruta es la unica recorrible sin
        // cuenta. 10 por minuto por IP; la IP es la real porque el gateway esta
        // como proxy confiable (bootstrap/app.php).
        RateLimiter::for('tracking', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        Gate::policy(Quote::class, QuotePolicy::class);
        Gate::policy(Shipment::class, ShipmentPolicy::class);
        Gate::policy(SupportTicket::class, SupportTicketPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
    }
}
