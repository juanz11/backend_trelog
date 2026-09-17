<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\Api\QuoteController as ApiQuoteController;
use App\Http\Controllers\Sso\MeController;

/*
|--------------------------------------------------------------------------
| API de TR3SLOG — TODO entra por el API Gateway del SSO
|--------------------------------------------------------------------------
|
| Desde el Lote 9 de integracion-sso (2026-09-16) este backend NO autentica:
| no hay `/login`, ni `/register`, ni Sanctum, ni contraseñas en `users`, ni
| tablas `roles`/`permissions`. La identidad la valida el gateway contra
| Passport y la entrega en cabeceras X-User-* (config/sso.php); los roles son
| los que el SSO emite en X-User-Roles para ESTA peticion, hidratados por
| `gateway.user` en la instancia de `$request->user()` (User.php).
|
| Cada prefijo de abajo es un `location` del gateway con `auth_request`, salvo
| `treslog/public`, que es un `location` sin identidad y con cupo por IP.
| Registrar una ruta fuera de estos prefijos es registrar una ruta que el
| gateway no puede alcanzar: 401 para siempre.
|
| Lo que se retiro y por que esta en openspec/changes/integracion-sso/4-tasks.md,
| Lote 9. Los invariantes (ninguna ruta sin `gateway.auth` fuera de public,
| ningun `auth:sanctum`, ningun `Hash::check`) los cuida
| tests/Feature/Sso/InvariantesEstructuralesTest.php y Lote9RetiroTest.php.
|
*/

// -----------------------------------------------------------------------
//  ¿Quien soy? — sin `sso.role` a proposito
// -----------------------------------------------------------------------
//  Es la unica ruta del prefijo `treslog` que no exige un rol: un cliente, un
//  conductor y una cuenta de operaciones tienen que poder preguntar quienes
//  son, y exigir un rol concreto obligaria a la web a adivinar cual pedir
//  antes de saber quien entro. La autorizacion de lo que HACE la sigue
//  decidiendo `sso.role` en cada ruta de dominio. `gateway.user` no se puede
//  omitir: sin el, `$request->user()` es null.
//
//  Si la identidad del SSO no tiene fila espejo en `users`, `gateway.user` la
//  da de alta (auto-provision, Lote 8) o responde 403 `forbidden` con
//  `request_id` cuando el correo ya existe sin ancla (lo vincula un
//  administrador con `sso:espejo`). Para la persona eso no es «no tenes
//  permiso»: la web lo muestra con ese texto y el `request_id`, y no reintenta.
Route::prefix('treslog')
    ->middleware(['gateway.auth', 'gateway.user'])
    ->group(function () {
        Route::get('/me', MeController::class);
    });

// -----------------------------------------------------------------------
//  Consola de administracion: solo `treslog:admin`
// -----------------------------------------------------------------------
//  El nombre del rol NO se escribe suelto: sale de config('sso.roles'). Un rol
//  sin el prefijo `treslog:` no da error en ningun lado —el SSO simplemente no
//  lo emite nunca— y con la config, el test estructural lo agarra.
Route::prefix('treslog')
    ->middleware(['gateway.auth', 'sso.role:'.config('sso.roles.admin')])
    ->group(function () {
        require __DIR__.'/admin-only.php';
    });

// -----------------------------------------------------------------------
//  La app de conductores: `treslog:driver`
// -----------------------------------------------------------------------
Route::prefix('treslog/driver')
    ->middleware(['gateway.auth', 'sso.role:'.config('sso.roles.driver'), 'gateway.user'])
    ->group(function () {
        require __DIR__.'/domain-driver.php';
    });

// -----------------------------------------------------------------------
//  El dominio de la web (clientes y consola de operaciones)
// -----------------------------------------------------------------------
//  `gateway.user` traduce la identidad a la fila de `users` de la que cuelgan
//  las nueve FKs del dominio y ahi mismo hidrata los roles del SSO en la
//  instancia: de eso viven `hasAnyRole()`/`isAdmin()` en controladores y
//  Policies.
//
//  `$operaciones` es la guarda de rol de la consola (choferes, incidentes,
//  padron, cotizaciones, cambiar/borrar envios): `treslog:operations` O
//  `treslog:admin`, con el 403 del contrato. Se define aca y la lee domain.php.
Route::prefix('treslog')
    ->middleware(['gateway.auth', 'gateway.user'])
    ->group(function () {
        $operaciones = ['sso.role:'.config('sso.roles.operations').','.config('sso.roles.admin')];

        require __DIR__.'/domain.php';
    });

// -----------------------------------------------------------------------
//  El camino PUBLICO por el gateway (D8.1, 2026-09-16)
// -----------------------------------------------------------------------
//  El equipo confirmo que contacto, cotizacion sin cuenta y tracking por codigo
//  son producto, no descuido: cualquiera cotiza y le llega por correo; el
//  tracking es «como UPS». El gateway les da un `location /api/treslog/public/`
//  SIN auth_request, con un cupo por IP (el gateway corre en este mismo host y
//  esta como proxy confiable, asi que la IP es la real). Nada mas entra por
//  aca: cada ruta se agrega a mano.
Route::prefix('treslog/public')
    ->middleware('throttle:30,1')
    ->group(function () {
        Route::post('/contact', [ContactController::class, 'store']);
        Route::post('/app/quotes', [ApiQuoteController::class, 'store']);
        // El tracking lleva un cupo mas corto propio (10/min por IP, ademas del
        // general): el codigo es secuencial y esta es la unica ruta que se puede
        // recorrer. Nadie consulta diez envios por minuto a mano.
        Route::get('/app/quotes/track/{tracking_code}', [ApiQuoteController::class, 'track'])->middleware('throttle:tracking');
    });
