<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AddressController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\DriverController;
use App\Http\Controllers\IncidentAdminController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\ShipmentController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\UserController;

/*
|--------------------------------------------------------------------------
| El dominio de la web — UNA definicion, DOS montajes (Lote 8)
|--------------------------------------------------------------------------
|
| Este archivo NO se registra solo. Lo hace `require` routes/api.php desde dos
| grupos distintos (3-design.md §E.2, "montaje en paralelo, no bifurcacion"),
| igual que routes/admin-only.php y routes/domain-driver.php:
|
|   1. `middleware('auth:sanctum')`          -> el camino VIVO: la web vieja y la
|                                              app de clientes, hasta el Lote 9
|   2. `prefix('treslog') + ['gateway.auth', 'gateway.user']`
|                                           -> el camino del SSO (H2: el gateway
|                                              entrega la URI completa)
|
| LAS RUTAS NO CAMBIARON NI UN CARACTER respecto de como vivian dentro del grupo
| `auth:sanctum` de routes/api.php. Lo unico nuevo es el grupo `$operaciones`.
|
|--------------------------------------------------------------------------
| `$operaciones`: la guarda de rol que solo existe en el montaje del SSO
|--------------------------------------------------------------------------
|
| Lo define el grupo que hace el `require`, ANTES de incluir este archivo:
|   - el montaje viejo no lo define -> `[]`, y las rutas quedan EXACTAMENTE como
|     estaban: la autorizacion la siguen decidiendo los controladores y las
|     Policies con `hasAnyRole()`/`isAdmin()`, que por ese camino leen `role_user`
|     como siempre (User.php, comportamiento (c)).
|   - el montaje del SSO lo define como `sso.role:treslog:operations,treslog:admin`
|     (OR), y ademas los controladores y Policies siguen corriendo: ahi
|     `hasAnyRole()` lee los roles que el gateway inyecto en X-User-Roles.
|
| POR QUE LA GUARDA VA EN LA RUTA Y NO SOLO EN EL CONTROLADOR: el 403 del
| controlador es `{"success":false,"message":"Unauthorized"}`, sin `request_id`
| y fuera del sobre cerrado del contrato. El de `sso.role` es el del contrato.
| Por el camino nuevo, quien no es de operaciones se va ANTES de tocar el dominio
| y con un error que soporte puede rastrear. Por el viejo, nada cambia.
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
|
| Ninguna ruta del camino nuevo es MAS abierta que la vieja. Varias son mas
| cerradas (las de la consola), y eso es deliberado: restringir no concede.
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
        Route::put('/{id}', [ShipmentController::class, 'update']);
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
