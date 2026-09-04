<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\ResetPasswordMail;
use App\Mail\WelcomeMail;
use App\Models\User;
use App\Models\Role;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'regex:/^(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}$/', 'confirmed'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'company' => $validated['company'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $customerRole = Role::where('name', 'customer')->first();
        if ($customerRole) {
            $user->roles()->attach($customerRole);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        try {
            Mail::to($user->email)->send(new WelcomeMail($user));
        } catch (\Throwable $e) {
            // El SMTP puede no estar configurado; el usuario se crea igual.
        }

        return response()->json([
            'success' => true,
            'message' => 'Usuario registrado correctamente.',
            'user' => $user->load('roles'),
            'token' => $token,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales no son correctas.'],
            ]);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Inicio de sesión exitoso.',
            'user' => $user->load('roles'),
            'token' => $token,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'user' => $request->user()->load('roles'),
        ]);
    }

    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if ($user) {
            $token = Str::random(64);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $data['email']],
                ['token' => $token, 'created_at' => now()]
            );

            try {
                Mail::to($data['email'])->send(new ResetPasswordMail($data['email'], $token));
            } catch (\Throwable $e) {
                // Si el SMTP no está configurado, no exponemos el error.
            }
        }

        return response()->json([
            'message' => 'Si el correo existe, recibirás un enlace para restablecer la contraseña.',
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'regex:/^(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}$/', 'confirmed'],
        ]);

        $record = DB::table('password_reset_tokens')
            ->where('email', $data['email'])
            ->where('token', $data['token'])
            ->first();

        if (! $record || Carbon::parse($record->created_at)->addMinutes(60)->isPast()) {
            throw ValidationException::withMessages([
                'token' => ['El enlace ha expirado o no es válido.'],
            ]);
        }

        User::where('email', $data['email'])->update([
            'password' => Hash::make($data['password']),
        ]);

        DB::table('password_reset_tokens')->where('email', $data['email'])->delete();

        return response()->json(['message' => 'Contraseña actualizada correctamente.']);
    }
}
