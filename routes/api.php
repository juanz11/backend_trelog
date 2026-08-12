<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\UserInvitationController;

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

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Authentication routes
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

// User Invitation Routes
Route::prefix('invitations')->group(function () {
    Route::post('/send', [UserInvitationController::class, 'sendInvitation']);
    Route::post('/bulk', [UserInvitationController::class, 'sendBulkInvitations']);
});

// Quotes
Route::post('/quotes', [QuoteController::class, 'store']);
Route::middleware('auth:sanctum')->get('/quotes', [QuoteController::class, 'index']);
Route::middleware('auth:sanctum')->get('/quotes/pending-count', [QuoteController::class, 'pendingCount']);
