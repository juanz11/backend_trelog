<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserInvitationController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\ShipmentController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\AddressController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\DriverController;
use App\Http\Controllers\IncidentAdminController;
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

// -----------------------------------------------------------------------
//  RETIRADA: `POST /users` (N3 del plan) — Lote 4, tarea 4.4
// -----------------------------------------------------------------------
//  El plan la llama "la cuarta via de alta" y pide retirarla o ponerla detras
//  de auth. SE RETIRA, pero el diagnostico del plan estaba mal y conviene
//  dejarlo escrito para que nadie la "arregle" el mes que viene:
//
//  N3 ES FALSO. Esta ruta NUNCA fue una via de alta. `UserController::store`
//  abre con `$this->authorize('create', User::class)`, y como la ruta estaba
//  FUERA de `auth:sanctum`, el guard por defecto es `web` (config/auth.php:19,
//  sesion) — un Bearer de Sanctum no se resuelve ahi. Comprobado con una
//  peticion real, no por lectura: `POST /api/users` con el Bearer de un admin
//  valido devuelve 403 "This action is unauthorized". Le daba 403 a TODO EL
//  MUNDO, admin incluido.
//
//  O sea que no habia agujero: habia codigo muerto. Y el codigo muerto de este
//  tipo es una trampa cargada — el dia que alguien la mueva adentro de
//  `auth:sanctum` "para que funcione", se convierte de verdad en la cuarta via
//  de alta que el plan creia estar cerrando. Por eso se borra en vez de
//  moverse: 3-design.md §E.1 ya le habia puesto "Muere. El alta la hace el SSO".
//
//  `UserController::store` queda en pie a proposito (lo usan las Policies y
//  muere entero en el Lote 9); lo que desaparece es la unica ruta que lo montaba.

// Public contact form
Route::post('/contact', [ContactController::class, 'store']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/alerts', [AlertController::class, 'index']);
    Route::get('/drivers', [DriverController::class, 'index']);
    Route::post('/drivers', [DriverController::class, 'store']);
    Route::get('/incidents', [IncidentAdminController::class, 'index']);
    Route::post('/incidents', [IncidentAdminController::class, 'store']);
    Route::patch('/incidents/{incident}/status', [IncidentAdminController::class, 'updateStatus']);

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
        Route::get('/clients', [UserController::class, 'clients']);
        Route::get('/{id}', [UserController::class, 'show']);
        Route::put('/{id}', [UserController::class, 'update']);
        Route::delete('/{id}', [UserController::class, 'destroy']);
    });

    // `roles/*`, `permissions/*` y `zones/*` SE MUDARON a routes/admin-only.php.
    // Siguen montadas en las MISMAS URLs y con el MISMO `auth:sanctum` — lo unico
    // que cambia es que ahora ademas exigen el rol admin. Ver el bloque de abajo.

    // Quote Management Routes
    Route::prefix('quotes')->group(function () {
        Route::get('/', [QuoteController::class, 'index']);
        Route::post('/', [QuoteController::class, 'store']);
        Route::get('/pending-count', [QuoteController::class, 'pendingCount']);
        Route::patch('/{quote}/status', [QuoteController::class, 'updateStatus']);
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

// -----------------------------------------------------------------------
//  Invitaciones: la mitad del invitado, y SOLO esa mitad
// -----------------------------------------------------------------------
//  El comentario "without auth for now" se retira junto con las cuatro rutas
//  administrativas que colgaban de aca (`send`, `bulk`, `pending`, `resend`):
//  se fueron a routes/admin-only.php y ahora exigen admin por los dos caminos.
//  Comprobado antes de tocar: `GET /api/invitations/pending` sin una sola
//  cabecera devolvia 200 con el email y el nombre de cada invitacion pendiente.
//
//  -- DIVERGENCIA respecto de 2-specs.md ("Invitaciones autenticadas") --------
//  La spec dice "en TODO endpoint de invitations/*". `verify` y `accept` se
//  quedan PUBLICAS, y no es una concesion: es que la regla, aplicada a estas
//  dos, se contradice sola. Quien las llama es el invitado, y el invitado
//  POR DEFINICION todavia no tiene cuenta — `acceptInvitation` es literalmente
//  el codigo que se la crea (UserInvitationController.php:252, `User::create`).
//  Exigir identidad resuelta para poder crear la identidad es pedir estar
//  adentro para poder entrar: no cierra ningun agujero, mata la funcion entera
//  y deja invitaciones que nadie puede aceptar nunca.
//
//  La credencial de estas dos ES el token de la invitacion, que va en el
//  cuerpo, es de un solo uso y vence (`isExpired()`, `isPending()`). Es el mismo
//  modelo que `/reset-password`, que tampoco pide estar logueado.
//
//  Lo que si queda expuesto y hay que decirlo: `verify` responde 404 con token
//  malo y 200 con el bueno, asi que permite adivinar tokens por fuerza bruta si
//  no hay rate limiting. No se arregla en este lote porque el destino entero de
//  UserInvitation depende de la Decision D4 (no tiene contraparte en el
//  contrato del SSO); se anota para que la decision se tome con el dato.
Route::prefix('invitations')->group(function () {
    Route::post('/verify', [UserInvitationController::class, 'verifyToken']);
    Route::post('/accept', [UserInvitationController::class, 'acceptInvitation']);
});

// -----------------------------------------------------------------------
//  Superficie de administracion — montaje 1 de 2: el camino VIVO
// -----------------------------------------------------------------------
//  Mismas URLs de siempre (`/api/roles`, `/api/permissions`, `/api/zones`) y el
//  mismo `auth:sanctum`. Lo unico que se agrega es `admin`, que es el cierre del
//  agujero 2 de `1-proposal.md §1`.
//
//  POR QUE NO SE REEMPLAZA POR `sso.role:treslog:admin`, que es lo que pide la
//  tarea 4.1: `sso.role` lee la identidad que deja `gateway.auth`, y
//  `gateway.auth` exige venir por el gateway. El gateway del SSO todavia NO esta
//  en el VPS (`contrato_api_v1_msh.md:353-355`, citado en `1-proposal.md §2`).
//  Montar solo la guarda nueva no cerraria el agujero: apagaria la
//  administracion de roles para el unico que hoy la usa legitimamente, el admin,
//  y encima sin arreglar nada que la guarda local no arregle igual. Se hacen los
//  DOS montajes, que es exactamente lo que manda `3-design.md §E.2`.
Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    require __DIR__.'/admin-only.php';
});

// -----------------------------------------------------------------------
//  Superficie de administracion — montaje 2 de 2: el camino del SSO
// -----------------------------------------------------------------------
//  H2 del plan: el gateway hace `proxy_pass` SIN componente de path
//  (gateway/templates/default.conf.template:126,203), asi que NGINX entrega la
//  URI completa y el backend recibe `/api/treslog/...`. Una ruta registrada como
//  `/api/roles` con `gateway.auth` seria inalcanzable por el gateway: 401 para
//  siempre. Por eso el prefijo, y por eso la propia spec escribe el escenario
//  como `GET /api/treslog/invitations/pending` (2-specs.md:205).
//
//  El nombre del rol NO se escribe suelto: sale de config('sso.roles'). Un rol
//  sin el prefijo `treslog:` no da error en ningun lado — el SSO simplemente no
//  lo emite nunca, la persona lo tiene asignado y la aplicacion no se entera.
//  Con el string a mano, alcanza equivocarse una vez; con la config, el test
//  estructural de InvariantesEstructuralesTest lo agarra.
Route::prefix('treslog')
    ->middleware(['gateway.auth', 'sso.role:'.config('sso.roles.admin')])
    ->group(function () {
        require __DIR__.'/admin-only.php';
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
