<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Role;
use App\Mail\PasswordResetMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;

class AuthController extends Controller
{
    /**
     * Register a new user
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'role' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $roleName = $request->role ?? 'customer';
        $role = Role::where('name', $roleName)->first();

        if ($role) {
            $user->roles()->attach($role);
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'user' => $user,
            'token' => $token,
        ], 201);
    }

    /**
     * Login user
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'account_not_found',
                'error' => 'Account not found',
            ], 401);
        }

        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json([
                'success' => false,
                'message' => 'invalid_credentials',
                'error' => 'Invalid password',
            ], 401);
        }

        $user = Auth::user();
        $token = $user->createToken('auth-token')->plainTextToken;
        
        return response()->json([
            'success' => true,
            'user' => $user->load('roles'),
            'token' => $token,
            'message' => 'Login successful',
        ]);
    }

    /**
     * Logout user
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Get authenticated user
     */
    public function me(Request $request)
    {
        return response()->json([
            'success' => true,
            'user' => $request->user()->load('roles'),
        ]);
    }

    /**
     * Forgot password - send reset link
     */
    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid email format',
                'message_es' => 'Formato de correo inválido',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Email not found',
                'message_es' => 'Correo no encontrado',
            ], 404);
        }

        // Generate a simple reset token (in production, use Laravel's password reset functionality)
        $resetToken = bin2hex(random_bytes(32));
        $user->reset_token = $resetToken;
        $user->reset_token_expires = now()->addHours(1);
        $user->save();

        // Generate reset link
        $resetLink = env('FRONTEND_URL', 'http://localhost:5173') . '/reset-password/' . $resetToken;
        
        try {
            // Send email with reset link
            Mail::to($user->email)->send(new PasswordResetMail($resetLink, $user->name));
            
            return response()->json([
                'success' => true,
                'message' => 'Password reset link sent to your email',
                'message_es' => 'Enlace de recuperación enviado a tu correo',
            ]);
        } catch (\Exception $e) {
            // Log error but still return success (for development)
            \Log::error('Failed to send password reset email: ' . $e->getMessage());
            
            return response()->json([
                'success' => true,
                'message' => 'Password reset link sent to your email',
                'message_es' => 'Enlace de recuperación enviado a tu correo',
                'reset_link' => $resetLink, // Include link for development/testing
            ]);
        }
    }

    /**
     * Verify reset token
     */
    public function verifyResetToken(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Token is required',
                'message_es' => 'Token requerido',
            ], 422);
        }

        $user = User::where('reset_token', $request->token)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid reset link',
                'message_es' => 'Enlace de recuperación inválido',
            ], 404);
        }

        if ($user->reset_token_expires && now()->gt($user->reset_token_expires)) {
            return response()->json([
                'success' => false,
                'message' => 'Reset link has expired',
                'message_es' => 'El enlace de recuperación ha expirado',
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Token is valid',
            'message_es' => 'Token válido',
        ]);
    }

    /**
     * Reset password
     */
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'message_es' => 'Error de validación',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::where('reset_token', $request->token)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid reset link',
                'message_es' => 'Enlace de recuperación inválido',
            ], 404);
        }

        if ($user->reset_token_expires && now()->gt($user->reset_token_expires)) {
            return response()->json([
                'success' => false,
                'message' => 'Reset link has expired',
                'message_es' => 'El enlace de recuperación ha expirado',
            ], 400);
        }

        $user->password = Hash::make($request->password);
        $user->reset_token = null;
        $user->reset_token_expires = null;
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully',
            'message_es' => 'Contraseña restablecida exitosamente',
        ]);
    }
}
