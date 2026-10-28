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
use App\Http\Controllers\DriverController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\IncidentAdminController;
use App\Http\Controllers\AuditFileController;
use App\Http\Controllers\PricingConfigController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\ShipmentRequestController;
use App\Http\Controllers\Api\AuthController as ApiAuthController;
use App\Http\Controllers\Api\QuoteController as ApiQuoteController;
use App\Http\Controllers\Api\Driver\DashboardController;
use App\Http\Controllers\Api\Driver\DriverAuthController;
use App\Http\Controllers\Api\Driver\IdentityController;
use App\Http\Controllers\Api\Driver\IncidentController;
use App\Http\Controllers\Api\Driver\PayrollController;
use App\Http\Controllers\Api\Driver\RouteController;
use App\Http\Controllers\Api\Driver\ShipmentController as DriverShipmentController;
use App\Http\Controllers\Api\Driver\StopController;
use App\Http\Controllers\Api\Driver\ManifestController as DriverManifestController;
use App\Http\Controllers\ManifestController;
use App\Http\Controllers\TransferRequestController;
use App\Http\Controllers\Hub\ManifestController as HubManifestController;

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

// Public routes (throttled: credentials, reset and mail-sending endpoints)
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
Route::post('/verify-reset-token', [AuthController::class, 'verifyResetToken'])->middleware('throttle:10,1');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');

// Public contact form
Route::post('/contact', [ContactController::class, 'store'])->middleware('throttle:5,1');

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::put('/user/password', [AuthController::class, 'changePassword']);
    Route::get('/alerts', [AlertController::class, 'index'])->middleware('permission:reports.view_ops');
    Route::get('/notifications', [AlertController::class, 'mine']);
    Route::post('/notifications/{id}/read', [AlertController::class, 'markRead']);
    Route::get('/drivers', [DriverController::class, 'index'])->middleware('permission:drivers.manage');
    Route::post('/drivers', [DriverController::class, 'store'])->middleware('permission:drivers.manage');
    Route::patch('/drivers/{user}', [DriverController::class, 'update'])->middleware('permission:drivers.manage');
    Route::get('/documents/pending', [DocumentController::class, 'pending'])->middleware('permission:drivers.manage');
    Route::post('/documents/{document}/review', [DocumentController::class, 'review'])->middleware('permission:drivers.manage');
    Route::get('/incidents', [IncidentAdminController::class, 'index'])->middleware('permission:incidents.view');
    Route::post('/incidents', [IncidentAdminController::class, 'store'])->middleware('permission:incidents.edit');
    Route::patch('/incidents/{incident}/status', [IncidentAdminController::class, 'updateStatus'])->middleware('permission:incidents.edit');

    // Audit complementary files
    Route::prefix('audit-files')->middleware('permission:audit.view_limited')->group(function () {
        Route::get('/', [AuditFileController::class, 'index']);
        Route::post('/', [AuditFileController::class, 'store']);
        Route::delete('/{auditFile}', [AuditFileController::class, 'destroy']);
    });

    // Finance portal
    Route::get('/finance/overview', [FinanceController::class, 'overview'])->middleware('permission:finance.view');

    // Pricing / calculation config
    Route::get('/pricing-config', [PricingConfigController::class, 'show']);
    Route::put('/pricing-config', [PricingConfigController::class, 'update'])->middleware('permission:pricing.edit');

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
        Route::post('/', [UserController::class, 'store'])->middleware('permission:users.create');
        Route::get('/', [UserController::class, 'index']);
        Route::get('/clients', [UserController::class, 'clients'])->middleware('permission:drivers.manage');
        Route::get('/{id}', [UserController::class, 'show']);
        Route::put('/{id}', [UserController::class, 'update']);
        Route::patch('/{id}/status', [UserController::class, 'updateStatus'])->middleware('permission:users.block');
        Route::delete('/{id}', [UserController::class, 'destroy']);
    });

    // Role Management Routes
    Route::prefix('roles')->group(function () {
        Route::get('/', [RoleController::class, 'index'])->middleware('permission:roles.view');
        Route::get('/{id}', [RoleController::class, 'show'])->middleware('permission:roles.view');
        Route::post('/', [RoleController::class, 'store'])->middleware('permission:roles.create');
        Route::put('/{id}', [RoleController::class, 'update'])->middleware('permission:roles.edit');
        Route::delete('/{id}', [RoleController::class, 'destroy'])->middleware('permission:roles.delete');
        Route::post('/{roleId}/permissions', [RoleController::class, 'addPermission'])->middleware('permission:roles.edit');
        Route::delete('/{roleId}/permissions/{permissionId}', [RoleController::class, 'removePermission'])->middleware('permission:roles.edit');
    });

    // Permission Management Routes
    Route::prefix('permissions')->group(function () {
        Route::get('/', [PermissionController::class, 'index'])->middleware('permission:permissions.view');
        Route::get('/{id}', [PermissionController::class, 'show'])->middleware('permission:permissions.view');
        Route::get('/module/{module}', [PermissionController::class, 'getByModule'])->middleware('permission:permissions.view');
        Route::post('/', [PermissionController::class, 'store'])->middleware('permission:permissions.create');
        Route::put('/{id}', [PermissionController::class, 'update'])->middleware('permission:permissions.edit');
        Route::delete('/{id}', [PermissionController::class, 'destroy'])->middleware('permission:permissions.delete');
    });

    // Zone Management Routes
    Route::prefix('zones')->group(function () {
        Route::get('/', [ZoneController::class, 'index']);
        Route::get('/{id}', [ZoneController::class, 'show']);
        Route::post('/', [ZoneController::class, 'store'])->middleware('permission:zones.manage');
        Route::put('/{id}', [ZoneController::class, 'update'])->middleware('permission:zones.manage');
        Route::delete('/{id}', [ZoneController::class, 'destroy'])->middleware('permission:zones.manage');
    });

    // Quote Management Routes
    Route::prefix('quotes')->group(function () {
        Route::get('/', [QuoteController::class, 'index']);
        Route::post('/', [QuoteController::class, 'store']);
        Route::get('/pending-count', [QuoteController::class, 'pendingCount']);
        Route::patch('/{quote}/status', [QuoteController::class, 'updateStatus']);
    });

    // Shipment Management Routes
    Route::prefix('shipments')->group(function () {
        Route::get('/requests/list', [ShipmentRequestController::class, 'index'])->middleware('permission:dispatch.manage');
        Route::get('/requests/pending-count', [ShipmentRequestController::class, 'pendingCount'])->middleware('permission:dispatch.manage');
        Route::patch('/requests/{shipmentRequest}', [ShipmentRequestController::class, 'updateStatus'])->middleware('permission:dispatch.manage');

        Route::get('/', [ShipmentController::class, 'index']);
        Route::post('/', [ShipmentController::class, 'store']);
        Route::get('/{id}', [ShipmentController::class, 'show']);
        Route::put('/{id}', [ShipmentController::class, 'update']);
        Route::patch('/{shipment}/driver', [ShipmentController::class, 'assignDriver']);
        Route::delete('/{id}', [ShipmentController::class, 'destroy']);
    });

    // Manifest Management Routes (Gestión de Manifiestos)
    Route::prefix('manifests')->group(function () {
        Route::get('/', [ManifestController::class, 'index'])->middleware('permission:manifests.view');
        Route::post('/', [ManifestController::class, 'store'])->middleware('permission:manifests.manage');
        Route::get('/{manifest}', [ManifestController::class, 'show'])->middleware('permission:manifests.view');
        Route::patch('/{manifest}/assignment', [ManifestController::class, 'assign'])->middleware('permission:manifests.manage');
        Route::put('/{manifest}/stops', [ManifestController::class, 'updateStops'])->middleware('permission:manifests.manage');
    });

    // Transfer requests (traspasos) — mesa de tráfico
    Route::prefix('transfer-requests')->group(function () {
        Route::get('/', [TransferRequestController::class, 'index'])->middleware('permission:manifests.view');
        Route::post('/{transferRequest}/resolve', [TransferRequestController::class, 'resolve'])->middleware('permission:manifests.manage');
    });

    // DCU-08 Hub y almacén: recepción/despacho contra manifiesto + ajustes
    Route::prefix('hub')->group(function () {
        Route::get('/manifests', [HubManifestController::class, 'index'])->middleware('permission:hub.view');
        Route::get('/manifests/{manifest}', [HubManifestController::class, 'show'])->middleware('permission:hub.view');
        Route::post('/manifests/{manifest}/scan', [HubManifestController::class, 'scan'])->middleware('permission:hub.manage');
        Route::post('/manifests/{manifest}/receive', [HubManifestController::class, 'receive'])->middleware('permission:hub.manage');
        Route::post('/manifests/{manifest}/dispatch', [HubManifestController::class, 'dispatchManifest'])->middleware('permission:hub.manage');
        Route::post('/manifests/{manifest}/adjustments', [HubManifestController::class, 'storeAdjustment'])->middleware('permission:hub.manage');
        Route::post('/adjustments/{adjustment}/resolve', [HubManifestController::class, 'resolveAdjustment'])->middleware('permission:hub.adjustments.approve');
    });
});

// User Invitation Routes — verify/accept are public (the invitee has no
// account yet); the management endpoints require users.create so invite
// tokens, pending invites and outbound mail are not publicly reachable.
Route::prefix('invitations')->group(function () {
    Route::post('/verify', [UserInvitationController::class, 'verifyToken'])->middleware('throttle:20,1');
    Route::post('/accept', [UserInvitationController::class, 'acceptInvitation'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/send', [UserInvitationController::class, 'sendInvitation'])->middleware('permission:users.create');
        Route::post('/bulk', [UserInvitationController::class, 'sendBulkInvitations'])->middleware('permission:users.create');
        Route::get('/pending', [UserInvitationController::class, 'getPendingInvitations'])->middleware('permission:users.create');
        Route::post('/resend', [UserInvitationController::class, 'resendInvitation'])->middleware('permission:users.create');
    });
});

// -----------------------------------------------------------------------
// Flutter App Routes (Api namespace - customer facing)
// -----------------------------------------------------------------------
Route::prefix('app')->group(function () {
    Route::post('/register', [ApiAuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/login', [ApiAuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/forgot-password', [ApiAuthController::class, 'forgot'])->middleware('throttle:5,1');
    Route::post('/reset-password', [ApiAuthController::class, 'reset'])->middleware('throttle:5,1');

    Route::post('/quotes', [ApiQuoteController::class, 'store'])->middleware('throttle:20,1');
    Route::get('/quotes/track/{tracking_code}', [ApiQuoteController::class, 'track'])->middleware('throttle:60,1');

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

        Route::get('/identity', [IdentityController::class, 'show']);
        Route::post('/identity', [IdentityController::class, 'store']);

        Route::get('/dashboard', [DashboardController::class, 'index']);

        Route::get('/routes', [RouteController::class, 'index']);
        Route::get('/routes/{route}', [RouteController::class, 'show']);
        Route::post('/routes/{route}/start', [RouteController::class, 'start']);
        Route::post('/routes/{route}/complete', [RouteController::class, 'complete']);

        Route::get('/shipments', [DriverShipmentController::class, 'index']);
        Route::get('/shipments/{shipment}', [DriverShipmentController::class, 'show']);
        Route::post('/shipments/{shipment}/request', [DriverShipmentController::class, 'request']);
        Route::patch('/shipments/{shipment}/status', [DriverShipmentController::class, 'updateStatus']);

        Route::post('/stops/{stop}/confirm', [StopController::class, 'confirm']);
        Route::post('/stops/{stop}/fail', [StopController::class, 'fail']);

        // Manifiestos del chofer (ciclo completo + POD + traspasos)
        Route::get('/manifests', [DriverManifestController::class, 'index']);
        Route::get('/manifests/{manifest}', [DriverManifestController::class, 'show']);
        Route::post('/manifests/{manifest}/assignment-response', [DriverManifestController::class, 'respondAssignment']);
        Route::post('/manifests/{manifest}/start', [DriverManifestController::class, 'start']);
        Route::post('/manifests/{manifest}/deliveries', [DriverManifestController::class, 'deliver']);
        Route::post('/manifests/{manifest}/failures', [DriverManifestController::class, 'fail']);
        Route::post('/manifests/{manifest}/transfer-requests', [DriverManifestController::class, 'requestTransfer']);
        Route::post('/manifests/{manifest}/assistance-resolved', [DriverManifestController::class, 'resolveAssistance']);
        Route::post('/manifests/{manifest}/finalize', [DriverManifestController::class, 'finalize']);
        Route::get('/manifests/{manifest}/handover', [DriverManifestController::class, 'handoverStatus']);
        Route::post('/manifests/{manifest}/handover/scan', [DriverManifestController::class, 'handoverScan']);
        Route::post('/manifests/{manifest}/handover/close', [DriverManifestController::class, 'handoverClose']);

        Route::get('/incidents', [IncidentController::class, 'index']);
        Route::post('/incidents', [IncidentController::class, 'store']);

        Route::get('/payroll', [PayrollController::class, 'index']);
    });
});
