<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsDriver
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->role !== 'driver') {
            return response()->json([
                'message' => 'Esta sección es exclusiva para conductores.',
            ], 403);
        }

        return $next($request);
    }
}
