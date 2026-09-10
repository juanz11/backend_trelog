<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Driver\DashboardController;
use App\Http\Controllers\Api\Driver\DriverAuthController;
use App\Http\Controllers\Api\Driver\IncidentController;
use App\Http\Controllers\Api\Driver\PayrollController;
use App\Http\Controllers\Api\Driver\RouteController;
use App\Http\Controllers\Api\Driver\StopController;

/*
|--------------------------------------------------------------------------
| Superficie del conductor — UNA definicion, DOS montajes
|--------------------------------------------------------------------------
|
| Este archivo NO se registra solo. Lo hace `require` routes/api.php desde dos
| grupos distintos (3-design.md §E.2, "montaje en paralelo, no bifurcacion"):
|
|   1. `prefix('driver')     + ['auth:sanctum', 'driver']`
|                                          -> el camino VIVO, el de la app
|                                             instalada en la calle (H3)
|   2. `prefix('treslog/driver') + ['gateway.auth', 'sso.role:treslog:driver',
|                                   'gateway.user']`
|                                          -> el camino del SSO
|
| POR QUE UN ARCHIVO COMPARTIDO Y NO COPIAR Y PEGAR: con las definiciones
| duplicadas, la primera ruta que alguien agregue en un solo bloque produce un
| endpoint que existe para la app vieja y no para el gateway (o al reves). El
| sintoma de ese error es "a mi me anda". Con `require`, agregar una ruta aca la
| protege por los dos caminos a la vez y el test estructural de
| InvariantesEstructuralesTest tiene una respuesta computable.
|
| LAS RUTAS DE ABAJO NO CAMBIARON NI UN CARACTER respecto de como vivian en
| routes/api.php. Este lote mueve texto y agrega un montaje; no toca el dominio.
|
|--------------------------------------------------------------------------
| POR QUE `logout` NO ESTA EN ESTE ARCHIVO
|--------------------------------------------------------------------------
|
| Se quedo suelto en el bloque viejo de routes/api.php, y no es un olvido.
| `DriverAuthController::logout` hace
|
|     $request->user()->currentAccessToken()->delete();
|
| y `currentAccessToken()` devuelve el token de Sanctum con el que se autentico
| la peticion. Por el camino del SSO NO HAY TOKEN DE SANCTUM: el gateway borra
| el Authorization antes del proxy (gateway/templates/default.conf.template, con
| `proxy_set_header Authorization ""`) y `ResolveDomainUser` deja el usuario con
| `auth()->setUser()`, que no adjunta ningun access token. O sea `null->delete()`,
| o sea 500 en la ruta que el cliente llama JUSTO cuando quiere irse.
|
| Y aunque no reventara, tampoco corresponde: por el camino nuevo la credencial
| es el Bearer de Passport que emitio el SSO, y revocarlo es competencia del SSO
| (`POST /api/logout` de su contrato), no de TR3SLOG. Un "logout" local que no
| invalida la credencial real es peor que no tener logout: miente.
|
| El invariante esta congelado en tests/Feature/Driver/RutasConductorPorGatewayTest.php
| ("logout NO se monta por el camino del SSO"). Si alguien mueve la linea para
| aca, ese test se pone rojo y explica por que.
*/

Route::get('/me', [DriverAuthController::class, 'me']);

Route::get('/dashboard', [DashboardController::class, 'index']);

Route::get('/routes', [RouteController::class, 'index']);
Route::get('/routes/{route}', [RouteController::class, 'show']);

Route::post('/stops/{stop}/confirm', [StopController::class, 'confirm']);
Route::post('/stops/{stop}/fail', [StopController::class, 'fail']);

Route::get('/incidents', [IncidentController::class, 'index']);
Route::post('/incidents', [IncidentController::class, 'store']);

Route::get('/payroll', [PayrollController::class, 'index']);
