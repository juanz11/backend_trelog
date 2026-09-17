<?php

use Illuminate\Support\Facades\Route;

// La unica ruta web. `/login` y `/dashboard` se retiraron con el Lote 9: la
// primera apuntaba a /api/login y /api/register, que ya no existen, y la
// segunda pedia un guard `auth` de sesion que este backend no tiene. Una
// ruta `login` con nombre tampoco hace falta: sin `auth` nadie redirige ahi.
Route::get('/', fn () => view('welcome'));
