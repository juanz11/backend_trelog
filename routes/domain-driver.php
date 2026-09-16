<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Driver\DashboardController;
use App\Http\Controllers\Api\Driver\MeController;
use App\Http\Controllers\Api\Driver\IncidentController;
use App\Http\Controllers\Api\Driver\PayrollController;
use App\Http\Controllers\Api\Driver\RouteController;
use App\Http\Controllers\Api\Driver\ShipmentController as DriverShipmentController;
use App\Http\Controllers\Api\Driver\StopController;

/*
|--------------------------------------------------------------------------
| Superficie del conductor
|--------------------------------------------------------------------------
|
| Este archivo NO se registra solo: lo hace `require` routes/api.php desde
| `prefix('treslog/driver') + ['gateway.auth', 'sso.role:treslog:driver',
| 'gateway.user']`. Hasta el Lote 9 se montaba TAMBIEN bajo `prefix('driver') +
| auth:sanctum` para la app vieja; esa app no existio nunca en la calle (D6) y
| el montaje se retiro con Sanctum. Sigue en archivo aparte para que la
| superficie del conductor tenga un solo lugar y un test estructural la cuente.
|
| No hay `logout` aca: la credencial es el Bearer de Passport que emitio el SSO
| y revocarlo es competencia del SSO (`POST /api/logout`), no de TR3SLOG.
*/

Route::get('/me', MeController::class);

Route::get('/dashboard', [DashboardController::class, 'index']);

Route::get('/routes', [RouteController::class, 'index']);
Route::get('/routes/{route}', [RouteController::class, 'show']);

// Envios del conductor (equipo TR3SLOG, 2026-09-16): listar, ver, reclamar y
// cambiar estado. Viven ACA y no en api.php para que el camino del SSO los
// tenga igual que el viejo, sin que nadie tenga que acordarse.
Route::get('/shipments', [DriverShipmentController::class, 'index']);
Route::get('/shipments/{shipment}', [DriverShipmentController::class, 'show']);
Route::post('/shipments/{shipment}/claim', [DriverShipmentController::class, 'claim']);
Route::patch('/shipments/{shipment}/status', [DriverShipmentController::class, 'updateStatus']);

Route::post('/stops/{stop}/confirm', [StopController::class, 'confirm']);
Route::post('/stops/{stop}/fail', [StopController::class, 'fail']);

Route::get('/incidents', [IncidentController::class, 'index']);
Route::post('/incidents', [IncidentController::class, 'store']);

Route::get('/payroll', [PayrollController::class, 'index']);
