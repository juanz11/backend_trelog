<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserInvitationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;

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

// User registration (public)
Route::post('/users', [UserController::class, 'store']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    
    // User Management Routes
    Route::prefix('users')->group(function () {
        Route::get('/', [UserController::class, 'index']);
        Route::get('/{id}', [UserController::class, 'show']);
        Route::put('/{id}', [UserController::class, 'update']);
        Route::delete('/{id}', [UserController::class, 'destroy']);
    });
});

// User Invitation Routes (without auth for now)
Route::prefix('invitations')->group(function () {
    Route::post('/send', [UserInvitationController::class, 'sendInvitation']);
    Route::post('/bulk', [UserInvitationController::class, 'sendBulkInvitations']);
    Route::post('/verify', [UserInvitationController::class, 'verifyToken']);
    Route::post('/accept', [UserInvitationController::class, 'acceptInvitation']);
    Route::get('/pending', [UserInvitationController::class, 'getPendingInvitations']);
    Route::post('/resend', [UserInvitationController::class, 'resendInvitation']);
});
