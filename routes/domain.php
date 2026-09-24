<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AddressController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\DriverController;
use App\Http\Controllers\IncidentAdminController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\ShipmentController;
use App\Http\Controllers\ShipmentRequestController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\UserController;

/*
|--------------------------------------------------------------------------
| El dominio de la web (clientes y consola de operaciones)
|--------------------------------------------------------------------------
|
| Este archivo NO se registra solo: lo hace `require` routes/api.php bajo
| `prefix('treslog') + ['gateway.auth', 'gateway.user']`, con `$operaciones`
| definido ANTES del `require`. Hasta el Lote 9 se montaba tambien bajo
| `auth:sanctum` para la web vieja; esa web ya no existe (Lote 7) y el montaje
| se retiro con Sanctum. Sigue en archivo aparte para que el dominio tenga un
| solo lugar y el test estructural lo cuente.
|
|--------------------------------------------------------------------------
| `$operaciones`: la guarda de rol de la consola
|--------------------------------------------------------------------------
|
| `sso.role:treslog:operations,treslog:admin` (OR). Ademas los controladores y
| Policies siguen corriendo: ahi `hasAnyRole()`/`isAdmin()` leen los roles que
| el gateway inyecto en X-User-Roles (User.php).
|
| POR QUE LA GUARDA VA EN LA RUTA Y NO SOLO EN EL CONTROLADOR: el 403 del
| controlador es `{"success":false,"message":"Unauthorized"}`, sin `request_id`
| y fuera del sobre cerrado del contrato. El de `sso.role` es el del contrato:
| quien no es de operaciones se va ANTES de tocar el dominio y con un error que
| soporte puede rastrear.
|
| QUE VA EN `$operaciones` Y QUE NO — la regla es la matriz de 3-design.md §D.3:
|   - operaciones/admin: choferes, incidentes, padron de usuarios, cotizaciones
|     de la consola, cambiar/borrar envios, y las alertas de la consola
|     (AlertController lista TODAS las cotizaciones y envios: es un tablero de
|     operaciones, no una pantalla de cliente).
|   - cliente (solo `gateway.user` + Policy de pertenencia): sus direcciones,
|     sus tickets, su propio usuario, sus envios (listar, crear, ver), y el
|     contador de cotizaciones pendientes, que el controlador ya filtra por
|     correo cuando quien pregunta no es de operaciones.
*/

$operaciones = $operaciones ?? [];

// -- Consola de operaciones ---------------------------------------------------
Route::middleware($operaciones)->group(function () {
    Route::get('/alerts', [AlertController::class, 'index']);
    Route::get('/drivers', [DriverController::class, 'index']);
    Route::post('/drivers', [DriverController::class, 'store']);
    Route::get('/incidents', [IncidentAdminController::class, 'index']);
    Route::post('/incidents', [IncidentAdminController::class, 'store']);
    Route::patch('/incidents/{incident}/status', [IncidentAdminController::class, 'updateStatus']);

    // User Management Routes (el padron). `GET|PUT /users/{id}` son del cliente
    // —la Policy deja ver y editar el propio— y viven mas abajo.
    Route::prefix('users')->group(function () {
        Route::get('/', [UserController::class, 'index']);
        Route::get('/clients', [UserController::class, 'clients']);
        Route::delete('/{id}', [UserController::class, 'destroy']);
    });

    // Quote Management Routes (la consola). `pending-count` es del cliente y
    // vive mas abajo.
    Route::prefix('quotes')->group(function () {
        Route::get('/', [QuoteController::class, 'index']);
        Route::post('/', [QuoteController::class, 'store']);
        Route::patch('/{quote}/status', [QuoteController::class, 'updateStatus']);
    });

    // Cambiar y borrar envios: ShipmentPolicy::update/delete son de
    // operaciones/admin, sin pertenencia.
    Route::prefix('shipments')->group(function () {
        // Solicitudes de recoleccion (equipo TR3SLOG, 2026-09-22): el conductor
        // pide un envio y operaciones aprueba o rechaza. Van ANTES de `/{id}`:
        // registrada al reves, `requests` entraria como un id de envio.
        Route::get('/requests/list', [ShipmentRequestController::class, 'index']);
        Route::get('/requests/pending-count', [ShipmentRequestController::class, 'pendingCount']);
        Route::patch('/requests/{shipmentRequest}', [ShipmentRequestController::class, 'updateStatus']);

        Route::put('/{id}', [ShipmentController::class, 'update']);
        // Despacho (equipo TR3SLOG, 2026-09-18): asignar/quitar conductor. Es de
        // la consola: va en el grupo de operaciones, y ademas ShipmentPolicy::assignDriver.
        Route::patch('/{shipment}/driver', [ShipmentController::class, 'assignDriver']);
        Route::delete('/{id}', [ShipmentController::class, 'destroy']);
    });
});

// -- Lo del cliente: pertenencia decidida por las Policies ---------------------

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

// El propio usuario (UserPolicy::view/update: admin o uno mismo)
Route::prefix('users')->group(function () {
    Route::get('/{id}', [UserController::class, 'show']);
    Route::put('/{id}', [UserController::class, 'update']);
});

Route::get('/quotes/pending-count', [QuoteController::class, 'pendingCount']);

// Shipment Management Routes (listar, crear, ver: ShipmentPolicy filtra por dueño)
Route::prefix('shipments')->group(function () {
    Route::get('/', [ShipmentController::class, 'index']);
    Route::post('/', [ShipmentController::class, 'store']);
    Route::get('/{id}', [ShipmentController::class, 'show']);
});
