<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * El padron local de TR3SLOG: las filas espejo de `users`.
 *
 * Desde el Lote 9 esto NO crea personas ni cambia contraseñas ni roles: todo
 * eso vive en el SSO. Lo que queda es leer el padron, editar los datos que son
 * de TR3SLOG (empresa, telefono, direccion, estado) y borrar una fila espejo.
 * `store()` se retiro: una persona nace en el SSO (registro o invitacion) y su
 * fila espejo la crea `gateway.user` la primera vez que entra, o
 * `DriverController::store` cuando Operaciones la da de alta como conductora.
 */
class UserController extends Controller
{
    /**
     * Get all users (admin only)
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()->get();
        
        // Remove duplicates based on email
        $uniqueUsers = [];
        $seenEmails = [];
        
        foreach ($users as $user) {
            if (!in_array($user->email, $seenEmails)) {
                $seenEmails[] = $user->email;
                $uniqueUsers[] = $user;
            }
        }
        
        return response()->json([
            'success' => true,
            'users' => $uniqueUsers,
        ]);
    }

    /**
     * Get specific user
     */
    public function show(Request $request, $id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        $this->authorize('view', $user);

        return response()->json([
            'success' => true,
            'user' => $user,
        ]);
    }

    /**
     * Update user (admin only or own user)
     */
    public function update(Request $request, $id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        $this->authorize('update', $user);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|string|email|max:255|unique:users,email,' . $id,
            'company' => 'sometimes|nullable|string|max:255',
            'phone' => 'sometimes|nullable|string|max:255',
            'status' => 'sometimes|in:active,pending,suspended',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // `password` y `roles` ya no se aceptan (Lote 9): la contraseña no
        // existe y los roles se cambian en el SSO. Si llegan, se ignoran: un
        // cliente viejo que los mande no rompe nada, pero tampoco cambia nada.

        if ($request->has('name')) {
            $user->name = $request->name;
        }
        if ($request->has('email')) {
            $user->email = $request->email;
        }
        if ($request->has('company')) {
            $user->company = $request->company;
        }
        if ($request->has('phone')) {
            $user->phone = $request->phone;
        }
        if ($request->has('status') && $request->user()->isAdmin()) {
            $user->status = $request->status;
        }

        $user->save();

        return response()->json([
            'success' => true,
            'user' => $user,
        ]);
    }

    /**
     * Delete user (admin only)
     */
    public function destroy(Request $request, $id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        $this->authorize('delete', $user);

        // Prevent admin from deleting themselves
        if ($request->user()->id == $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete your own account',
            ], 400);
        }

        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully',
        ]);
    }

    public function clients(Request $request)
    {
        if (! $request->user()->hasAnyRole(['admin', 'operations'])) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        // Un cliente es alguien a quien el SSO le emitio `treslog:customer` la
        // ultima vez que entro (`sso_roles`, la foto que deja ResolveDomainUser) y
        // que no es conductor. Quien nunca entro no tiene foto y no aparece: no
        // puede tener envios todavia. Antes se leia `role_user`, que ya no existe.
        $clients = User::query()
            ->whereJsonContains('sso_roles', (string) config('sso.roles.customer'))
            ->whereDoesntHave('driverProfile')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'phone']);

        return response()->json($clients);
    }
}
