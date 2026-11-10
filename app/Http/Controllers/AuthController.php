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
use Illuminate\Support\Facades\Cache;

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
            'phone' => 'nullable|string|max:25',
            'password' => 'required|string|regex:/^(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}$/',
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
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
        ]);

        // El rol nunca viene del request: todo registro público es `customer`.
        $role = Role::where('name', 'customer')->first();

        if ($role) {
            $user->assignRole($role);
        }

        $code = (string) random_int(100000, 999999);
        $user->update(['verification_code' => $code]);
        \Illuminate\Support\Facades\Log::info("Código de verificación para {$user->email}: {$code}");
        \Illuminate\Support\Facades\Mail::to($user)->send(new \App\Mail\VerificationCodeMail($code));

        return response()->json([
            'success' => true,
            'requires_verification' => true,
            'email' => $user->email,
            'verification_code' => $code,
        ], 201);
    }

    /**
     * Verify account code
     */
    public function verifyCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'code' => 'required|string|size:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::where('verification_code', $request->code)
            ->where(function ($q) use ($request) {
                $q->where('email', $request->email)->orWhere('pending_email', $request->email);
            })
            ->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'invalid_code',
                'error' => 'Código de verificación inválido.',
            ], 403);
        }

        $updates = [
            'email_verified_at' => now(),
            'verification_code' => null,
        ];
        if ($user->pending_email && $user->pending_email === $request->email) {
            $updates['email'] = $user->pending_email;
            $updates['pending_email'] = null;
        }
        $user->update($updates);

        return response()->json([
            'success' => true,
            'email_verified' => true,
        ]);
    }

    /**
     * Verify phone code (placeholder: acepta '1234')
     */
    public function verifyPhone(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'code' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($request->code !== '1234') {
            return response()->json([
                'success' => false,
                'message' => 'invalid_code',
                'error' => 'Código de verificación inválido.',
            ], 403);
        }

        $user = User::where('email', $request->email)
            ->orWhere('pending_email', $request->email)
            ->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'account_not_found',
            ], 404);
        }

        $pupdates = ['phone_verified_at' => now()];
        if ($user->pending_phone) {
            $pupdates['phone'] = $user->pending_phone;
            $pupdates['pending_phone'] = null;
        }
        $user->update($pupdates);

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'user' => $user->load('roles'),
            'token' => $token,
        ]);
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

        // Respuesta genérica: no revelar si la cuenta existe (enumeración).
        if (!$user || !Auth::attempt($request->only('email', 'password'))) {
            return response()->json([
                'success' => false,
                'message' => 'invalid_credentials',
                'error' => 'Invalid credentials',
            ], 401);
        }

        $user = Auth::user();

        if ($user->status === 'suspended') {
            Auth::logout();
            return response()->json([
                'success' => false,
                'message' => 'account_suspended',
                'error' => 'Account suspended',
            ], 403);
        }

        // Clientes deben tener correo y teléfono verificados.
        if ($user->hasAnyRole(['customer', 'executive_client']) && (is_null($user->email_verified_at) || is_null($user->phone_verified_at))) {
            Auth::logout();
            $vcode = $user->verification_code ?: (string) random_int(100000, 999999);
            if (!$user->verification_code) {
                $user->update(['verification_code' => $vcode]);
            }
            \Illuminate\Support\Facades\Mail::to($user)->send(new \App\Mail\VerificationCodeMail($vcode));
            return response()->json([
                'success' => false,
                'message' => 'unverified_account',
                'code' => $vcode,
                'error' => 'Debes verificar tu correo y teléfono antes de iniciar sesión.',
            ], 403);
        }

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

        $genericResponse = [
            'success' => true,
            'message' => 'If the email is registered, a reset link will be sent',
            'message_es' => 'Si el correo está registrado, recibirás un enlace de recuperación',
        ];

        $user = User::where('email', $request->email)->first();

        // Respuesta genérica: no revelar si la cuenta existe.
        if (!$user) {
            return response()->json($genericResponse);
        }

        // El token viaja en el enlace; en la base se guarda solo el hash.
        $resetToken = bin2hex(random_bytes(32));
        $user->reset_token = hash('sha256', $resetToken);
        $user->reset_token_expires = now()->addHours(1);
        $user->save();

        // Generate reset link
        $resetLink = config('app.frontend_url') . '/reset?token=' . urlencode($resetToken) . '&email=' . urlencode($user->email);

        try {
            // Send email with reset link
            Mail::to($user->email)->send(new PasswordResetMail($resetLink, $user->name));
        } catch (\Exception $e) {
            \Log::error('Failed to send password reset email: ' . $e->getMessage());
        }

        return response()->json($genericResponse);
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

        $user = User::where('reset_token', hash('sha256', $request->token))->first();

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
            'password' => 'required|string|regex:/^(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}$/',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'message_es' => 'Error de validación',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::where('reset_token', hash('sha256', $request->token))->first();

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

        // Invalidar sesiones existentes tras el reseteo.
        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully',
            'message_es' => 'Contraseña restablecida exitosamente',
        ]);
    }

    /**
     * Change password (authenticated). After 3 failed current-password
     * attempts it triggers the forgot-password flow automatically.
     */
    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'password' => 'required|string|regex:/^(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}$/|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'message_es' => 'Error de validación',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        $cacheKey = 'pwd_attempts:' . $user->id;

        if (! Hash::check($request->current_password, $user->password)) {
            $attempts = (int) Cache::get($cacheKey, 0) + 1;
            Cache::put($cacheKey, $attempts, now()->addMinutes(15));

            if ($attempts >= 3) {
                Cache::forget($cacheKey);

                $resetToken = bin2hex(random_bytes(32));
                $user->reset_token = hash('sha256', $resetToken);
                $user->reset_token_expires = now()->addHours(1);
                $user->save();

                $resetLink = config('app.frontend_url') . '/reset?token=' . urlencode($resetToken) . '&email=' . urlencode($user->email);

                try {
                    Mail::to($user->email)->send(new PasswordResetMail($resetLink, $user->name));
                } catch (\Exception $e) {
                    \Log::error('Failed to send password reset email: ' . $e->getMessage());
                }

                return response()->json([
                    'success' => false,
                    'too_many_attempts' => true,
                    'message' => 'Too many failed attempts. A recovery link was sent to your email.',
                    'message_es' => 'Demasiados intentos fallidos. Te enviamos un enlace de recuperación a tu correo.',
                ], 429);
            }

            return response()->json([
                'success' => false,
                'attempts_left' => 3 - $attempts,
                'message' => 'Current password is incorrect',
                'message_es' => 'La contraseña actual es incorrecta',
            ], 422);
        }

        Cache::forget($cacheKey);
        $user->password = Hash::make($request->password);
        $user->save();

        // Revocar las demás sesiones: la contraseña vieja ya no sirve para nada.
        $current = $user->currentAccessToken();
        $user->tokens()->when($current, fn ($q) => $q->where('id', '!=', $current->id))->delete();

        return response()->json([
            'success' => true,
            'message' => 'Password updated successfully',
            'message_es' => 'Contraseña actualizada correctamente',
        ]);
    }
}
