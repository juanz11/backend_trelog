<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/treslog/driver/me`: quien soy, como conductor.
 *
 * Era `DriverAuthController::me`, el unico metodo de ese controlador que
 * sobrevivio al Lote 9: `register`, `login` y `logout` se fueron con Sanctum.
 * La app de conductores lo llama al abrir para saber a la vez si el token vale
 * (401 lo dice el gateway) y si la persona sigue siendo conductora (403 lo dice
 * `sso.role`). `$request->user()` es la fila espejo que dejo ResolveDomainUser.
 */
class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json($request->user()->load('driverProfile'));
    }
}
