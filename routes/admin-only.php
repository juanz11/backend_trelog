<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\UserInvitationController;
use App\Http\Controllers\ZoneController;

/*
|--------------------------------------------------------------------------
| Superficie de administracion — UNA definicion, DOS montajes
|--------------------------------------------------------------------------
|
| Este archivo NO se registra solo. Lo hace `require` routes/api.php desde dos
| grupos distintos (3-design.md §E.2, "montaje en paralelo, no bifurcacion"):
|
|   1. `['auth:sanctum', 'admin']`                  -> el camino VIVO de hoy
|   2. `prefix('treslog') + ['gateway.auth', 'sso.role:treslog:admin']`
|                                                   -> el camino del SSO
|
| POR QUE UN ARCHIVO COMPARTIDO Y NO COPIAR Y PEGAR: con las definiciones
| duplicadas, la primera ruta que alguien agregue en un solo bloque produce un
| endpoint que existe para la web vieja y no para el gateway (o al reves). El
| sintoma de ese error es "a mi me anda". Con `require`, agregar una ruta aca la
| protege en los dos caminos a la vez, y el test estructural de
| InvariantesEstructuralesTest tiene una respuesta computable.
|
| POR QUE ESTAS RUTAS Y NO OTRAS: son los agujeros 2 y 3 de `1-proposal.md §1`,
| verificados en este repo antes de tocar nada:
|
|   GET  /api/roles        con un `customer` autenticado -> 200 (era 200)
|   POST /api/permissions  con un `customer` autenticado -> 201 (creaba permisos)
|   GET  /api/invitations/pending SIN NINGUNA CABECERA   -> 200 con emails
|
| O sea: cualquier cliente autoregistrado podia reescribir los permisos del rol
| `admin`, y cualquier persona de internet podia listar las invitaciones
| pendientes y disparar correo masivo desde el dominio de TR3SLOG.
|
| POR QUE NO VA `gateway.user` EN EL MONTAJE 2: ninguno de los cuatro
| controladores de este archivo toca `$request->user()` ni `authorize()`
| (verificado con grep sobre RoleController, PermissionController,
| ZoneController y UserInvitationController). Montar `gateway.user` aca no
| habilitaria nada y si agregaria un modo de falla: `ResolveDomainUser` responde
| 403 a quien no tenga fila local con `sso_user_id`, y la migracion de cuentas a
| Clerk esta explicitamente fuera de alcance. Los grupos que SI necesitan el
| usuario de dominio (conductor, envios) lo montan en los Lotes 5 y 8.
*/

// -----------------------------------------------------------------------
//  RBAC local (muere entero en el Lote 9, junto con las tablas)
// -----------------------------------------------------------------------
Route::prefix('roles')->group(function () {
    Route::get('/', [RoleController::class, 'index']);
    Route::get('/{id}', [RoleController::class, 'show']);
    Route::post('/', [RoleController::class, 'store']);
    Route::put('/{id}', [RoleController::class, 'update']);
    Route::delete('/{id}', [RoleController::class, 'destroy']);
    Route::post('/{roleId}/permissions', [RoleController::class, 'addPermission']);
    Route::delete('/{roleId}/permissions/{permissionId}', [RoleController::class, 'removePermission']);
});

Route::prefix('permissions')->group(function () {
    Route::get('/', [PermissionController::class, 'index']);
    Route::get('/{id}', [PermissionController::class, 'show']);
    Route::get('/module/{module}', [PermissionController::class, 'getByModule']);
    Route::post('/', [PermissionController::class, 'store']);
    Route::put('/{id}', [PermissionController::class, 'update']);
    Route::delete('/{id}', [PermissionController::class, 'destroy']);
});

Route::prefix('zones')->group(function () {
    Route::get('/', [ZoneController::class, 'index']);
    Route::get('/{id}', [ZoneController::class, 'show']);
    Route::post('/', [ZoneController::class, 'store']);
    Route::put('/{id}', [ZoneController::class, 'update']);
    Route::delete('/{id}', [ZoneController::class, 'destroy']);
});

// -----------------------------------------------------------------------
//  Invitaciones: SOLO la mitad administrativa
// -----------------------------------------------------------------------
//  `verify` y `accept` NO estan aca y siguen publicas en routes/api.php. Ver el
//  comentario largo alla: quien las llama es el invitado, que por definicion
//  todavia NO TIENE CUENTA — `acceptInvitation` es justamente quien se la crea.
//  Ponerlas detras de una guarda de identidad no cierra ningun agujero: mata la
//  funcion, porque exige estar adentro para poder entrar.
Route::prefix('invitations')->group(function () {
    Route::post('/send', [UserInvitationController::class, 'sendInvitation']);
    Route::post('/bulk', [UserInvitationController::class, 'sendBulkInvitations']);
    Route::get('/pending', [UserInvitationController::class, 'getPendingInvitations']);
    Route::post('/resend', [UserInvitationController::class, 'resendInvitation']);
});
