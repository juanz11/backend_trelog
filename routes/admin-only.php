<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ZoneController;

/*
|--------------------------------------------------------------------------
| Superficie de administracion: solo `treslog:admin`
|--------------------------------------------------------------------------
|
| La monta routes/api.php bajo `prefix('treslog') + ['gateway.auth',
| 'sso.role:treslog:admin']`. Hasta el Lote 9 se montaba tambien bajo
| `['auth:sanctum', 'admin']` y traia roles, permisos e invitaciones locales:
| todo eso vive ahora en el SSO (roles y permisos por aplicacion, altas por
| invitacion), y las tablas locales se borraron. Lo unico que quedo como
| administracion propia de TR3SLOG son las zonas.
|
| Sin `gateway.user`: ZoneController no toca `$request->user()`. Si alguna ruta
| nueva lo necesita, va en domain.php bajo `$operaciones`, no aca.
*/

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
