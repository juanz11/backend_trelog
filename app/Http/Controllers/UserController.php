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

        // `name` y `email` NO se editan aca: los gobierna el SSO y ResolveDomainUser
        // los refresca en cada peticion (se pisaria lo que se escriba). Peor: un
        // email cambiado a mano bloqueaba el alta automatica de OTRA persona con
        // ese correo (revision de jueces 2026-09-16, menor). Si llegan, se ignoran.
        $validator = Validator::make($request->all(), [
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

    /**
     * Block / unblock a user (admin or users.block permission)
     */
    public function updateStatus(Request $request, $id)
    {
        $actor = $request->user();

        // Sin `with('roles')` ni `hasRole()` sobre `$user` (merge con la rama del
        // SSO): la relacion `roles()` se retiro con la tabla, y `hasRole()` en una
        // instancia de `User::find()` —no hidratada por gateway.user— LANZA
        // LogicException, porque los roles son de la peticion y no de la fila.
        // El "es admin" del objetivo se lee de `sso_roles` (la foto que deja
        // ResolveDomainUser); el del actor, de la instancia hidratada.
        $user = User::find($id);
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        $data = $request->validate([
            'status' => ['required', 'in:active,pending,suspended'],
        ]);

        if ($user->id === $actor->id) {
            return response()->json([
                'success' => false,
                'message' => 'No puedes cambiar tu propio estado.',
            ], 422);
        }

        if (in_array((string) config('sso.roles.admin'), $user->sso_roles ?? [], true)
            && ! $actor->hasRole('admin')) {
            return response()->json([
                'success' => false,
                'message' => 'No puedes modificar a un administrador.',
            ], 403);
        }

        $user->status = $data['status'];
        $user->save();

        // Sin `$user->tokens()->delete()`: ya no hay tokens Sanctum que revocar.
        // La suspension se aplica en `gateway.user` (ResolveDomainUser), que
        // responde 403 a cada peticion mientras `status` siga en 'suspended'.
        return response()->json([
            'success' => true,
            'user' => $user,
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
