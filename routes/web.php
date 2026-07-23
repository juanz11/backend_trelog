<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/login', function () {
    return response()->json([
        'message' => 'Please use API routes for authentication',
        'api_login' => '/api/login',
        'api_register' => '/api/register',
    ]);
})->name('login');
