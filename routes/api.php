<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\QuoteController;
use App\Http\Controllers\Api\Driver\DashboardController;
use App\Http\Controllers\Api\Driver\DriverAuthController;
use App\Http\Controllers\Api\Driver\IncidentController;
use App\Http\Controllers\Api\Driver\PayrollController;
use App\Http\Controllers\Api\Driver\RouteController;
use App\Http\Controllers\Api\Driver\StopController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgot']);
Route::post('/reset-password', [AuthController::class, 'reset']);

Route::post('/quotes', [QuoteController::class, 'store']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/quotes', [QuoteController::class, 'index']);
    Route::get('/quotes/pending-count', [QuoteController::class, 'pendingCount']);
    Route::get('/quotes/{quote}', [QuoteController::class, 'show']);
    Route::post('/quotes/{quote}/viewed', [QuoteController::class, 'markViewed']);
    Route::patch('/quotes/{quote}/status', [QuoteController::class, 'updateStatus']);
});

// -----------------------------------------------------------------------
// Driver app (tr3slog_driver_app)
// -----------------------------------------------------------------------
Route::prefix('driver')->group(function () {
    Route::post('/register', [DriverAuthController::class, 'register']);
    Route::post('/login', [DriverAuthController::class, 'login']);

    Route::middleware(['auth:sanctum', 'driver'])->group(function () {
        Route::get('/me', [DriverAuthController::class, 'me']);
        Route::post('/logout', [DriverAuthController::class, 'logout']);

        Route::get('/dashboard', [DashboardController::class, 'index']);

        Route::get('/routes', [RouteController::class, 'index']);
        Route::get('/routes/{route}', [RouteController::class, 'show']);

        Route::post('/stops/{stop}/confirm', [StopController::class, 'confirm']);
        Route::post('/stops/{stop}/fail', [StopController::class, 'fail']);

        Route::get('/incidents', [IncidentController::class, 'index']);
        Route::post('/incidents', [IncidentController::class, 'store']);

        Route::get('/payroll', [PayrollController::class, 'index']);
    });
});
