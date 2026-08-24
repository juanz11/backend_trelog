<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserInvitationController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\ZoneController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\ShipmentController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\AddressController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\Api\AuthController as ApiAuthController;
use App\Http\Controllers\Api\QuoteController as ApiQuoteController;
use App\Http\Controllers\Api\Driver\DashboardController;
use App\Http\Controllers\Api\Driver\DriverAuthController;
use App\Http\Controllers\Api\Driver\IncidentController;
use App\Http\Controllers\Api\Driver\PayrollController;
use App\Http\Controllers\Api\Driver\RouteController;
use App\Http\Controllers\Api\Driver\StopController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/verify-reset-token', [AuthController::class, 'verifyResetToken']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

// User registration (public)
Route::post('/users', [UserController::class, 'store']);

// Public contact form
Route::post('/contact', [ContactController::class, 'store']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/alerts', [AlertController::class, 'index']);

    // Address Management Routes
    Route::prefix('addresses')->group(function () {
        Route::get('/', [AddressController::class, 'index']);
        Route::post('/', [AddressController::class, 'store']);
        Route::get('/{address}', [AddressController::class, 'show']);
        Route::put('/{address}', [AddressController::class, 'update']);
        Route::delete('/{address}', [AddressController::class, 'destroy']);
    });

    // Support Tickets Routes
    Route::prefix('support')->group(function () {
        Route::post('/', [SupportController::class, 'store']);
        Route::get('/', [SupportController::class, 'index']);
        Route::get('/{ticket}', [SupportController::class, 'show']);
        Route::put('/{ticket}', [SupportController::class, 'update']);
    });

    // User Management Routes
    Route::prefix('users')->group(function () {
        Route::get('/', [UserController::class, 'index']);
        Route::get('/{id}', [UserController::class, 'show']);
        Route::put('/{id}', [UserController::class, 'update']);
        Route::delete('/{id}', [UserController::class, 'destroy']);
    });

    // Role Management Routes
    Route::prefix('roles')->group(function () {
        Route::get('/', [RoleController::class, 'index']);
        Route::get('/{id}', [RoleController::class, 'show']);
        Route::post('/', [RoleController::class, 'store']);
        Route::put('/{id}', [RoleController::class, 'update']);
        Route::delete('/{id}', [RoleController::class, 'destroy']);
        Route::post('/{roleId}/permissions', [RoleController::class, 'addPermission']);
        Route::delete('/{roleId}/permissions/{permissionId}', [RoleController::class, 'removePermission']);
    });

    // Permission Management Routes
    Route::prefix('permissions')->group(function () {
        Route::get('/', [PermissionController::class, 'index']);
        Route::get('/{id}', [PermissionController::class, 'show']);
        Route::get('/module/{module}', [PermissionController::class, 'getByModule']);
        Route::post('/', [PermissionController::class, 'store']);
        Route::put('/{id}', [PermissionController::class, 'update']);
        Route::delete('/{id}', [PermissionController::class, 'destroy']);
    });

    // Zone Management Routes
    Route::prefix('zones')->group(function () {
        Route::get('/', [ZoneController::class, 'index']);
        Route::get('/{id}', [ZoneController::class, 'show']);
        Route::post('/', [ZoneController::class, 'store']);
        Route::put('/{id}', [ZoneController::class, 'update']);
        Route::delete('/{id}', [ZoneController::class, 'destroy']);
    });

    // Quote Management Routes
    Route::prefix('quotes')->group(function () {
        Route::get('/', [QuoteController::class, 'index']);
        Route::post('/', [QuoteController::class, 'store']);
        Route::get('/pending-count', [QuoteController::class, 'pendingCount']);
    });

    // Shipment Management Routes
    Route::prefix('shipments')->group(function () {
        Route::get('/', [ShipmentController::class, 'index']);
        Route::post('/', [ShipmentController::class, 'store']);
        Route::get('/{id}', [ShipmentController::class, 'show']);
        Route::put('/{id}', [ShipmentController::class, 'update']);
        Route::delete('/{id}', [ShipmentController::class, 'destroy']);
    });
});

// User Invitation Routes (without auth for now)
Route::prefix('invitations')->group(function () {
    Route::post('/send', [UserInvitationController::class, 'sendInvitation']);
    Route::post('/bulk', [UserInvitationController::class, 'sendBulkInvitations']);
    Route::post('/verify', [UserInvitationController::class, 'verifyToken']);
    Route::post('/accept', [UserInvitationController::class, 'acceptInvitation']);
    Route::get('/pending', [UserInvitationController::class, 'getPendingInvitations']);
    Route::post('/resend', [UserInvitationController::class, 'resendInvitation']);
});

// -----------------------------------------------------------------------
// Flutter App Routes (Api namespace - customer facing)
// -----------------------------------------------------------------------
Route::prefix('app')->group(function () {
    Route::post('/register', [ApiAuthController::class, 'register']);
    Route::post('/login', [ApiAuthController::class, 'login']);
    Route::post('/forgot-password', [ApiAuthController::class, 'forgot']);
    Route::post('/reset-password', [ApiAuthController::class, 'reset']);

    Route::post('/quotes', [ApiQuoteController::class, 'store']);
    Route::get('/quotes/track/{tracking_code}', [ApiQuoteController::class, 'track']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/user', [ApiAuthController::class, 'user']);
        Route::post('/logout', [ApiAuthController::class, 'logout']);

        Route::get('/quotes', [ApiQuoteController::class, 'index']);
        Route::get('/quotes/pending-count', [ApiQuoteController::class, 'pendingCount']);
        Route::get('/quotes/{quote}', [ApiQuoteController::class, 'show']);
        Route::post('/quotes/{quote}/viewed', [ApiQuoteController::class, 'markViewed']);
        Route::patch('/quotes/{quote}/status', [ApiQuoteController::class, 'updateStatus']);
    });
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
