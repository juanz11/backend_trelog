<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RoleController extends Controller
{
    /**
     * Get all roles
     */
    public function index()
    {
        $roles = Role::with('permissions')->get();
        return response()->json([
            'success' => true,
            'roles' => $roles,
        ]);
    }

    /**
     * Get a specific role
     */
    public function show($id)
    {
        $role = Role::with('permissions')->find($id);
        
        if (!$role) {
            return response()->json([
                'success' => false,
                'message' => 'Role not found',
                'message_es' => 'Rol no encontrado',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'role' => $role,
        ]);
    }

    /**
     * Create a new role
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|unique:roles',
            'display_name' => 'required|string',
            'description' => 'nullable|string',
            'permissions' => 'nullable|array',
            'permissions.*' => 'exists:permissions,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'message_es' => 'Error de validación',
                'errors' => $validator->errors(),
            ], 422);
        }

        $role = Role::create([
            'name' => $request->name,
            'display_name' => $request->display_name,
            'description' => $request->description,
        ]);

        // Attach permissions if provided
        if ($request->has('permissions')) {
            $role->permissions()->attach($request->permissions);
        }

        return response()->json([
            'success' => true,
            'message' => 'Role created successfully',
            'message_es' => 'Rol creado exitosamente',
            'role' => $role->load('permissions'),
        ], 201);
    }

    /**
     * Update a role
     */
    public function update(Request $request, $id)
    {
        $role = Role::find($id);
        
        if (!$role) {
            return response()->json([
                'success' => false,
                'message' => 'Role not found',
                'message_es' => 'Rol no encontrado',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|unique:roles,name,' . $id,
            'display_name' => 'sometimes|string',
            'description' => 'nullable|string',
            'permissions' => 'nullable|array',
            'permissions.*' => 'exists:permissions,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'message_es' => 'Error de validación',
                'errors' => $validator->errors(),
            ], 422);
        }

        $role->update($request->only(['name', 'display_name', 'description']));

        // Sync permissions if provided
        if ($request->has('permissions')) {
            $role->permissions()->sync($request->permissions);
        }

        return response()->json([
            'success' => true,
            'message' => 'Role updated successfully',
            'message_es' => 'Rol actualizado exitosamente',
            'role' => $role->load('permissions'),
        ]);
    }

    /**
     * Delete a role
     */
    public function destroy($id)
    {
        $role = Role::find($id);
        
        if (!$role) {
            return response()->json([
                'success' => false,
                'message' => 'Role not found',
                'message_es' => 'Rol no encontrado',
            ], 404);
        }

        // Detach permissions before deleting
        $role->permissions()->detach();
        $role->delete();

        return response()->json([
            'success' => true,
            'message' => 'Role deleted successfully',
            'message_es' => 'Rol eliminado exitosamente',
        ]);
    }

    /**
     * Add permission to role
     */
    public function addPermission(Request $request, $roleId)
    {
        $role = Role::find($roleId);
        
        if (!$role) {
            return response()->json([
                'success' => false,
                'message' => 'Role not found',
                'message_es' => 'Rol no encontrado',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'permission_id' => 'required|exists:permissions,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'message_es' => 'Error de validación',
                'errors' => $validator->errors(),
            ], 422);
        }

        $role->permissions()->attach($request->permission_id);

        return response()->json([
            'success' => true,
            'message' => 'Permission added to role successfully',
            'message_es' => 'Permiso agregado al rol exitosamente',
            'role' => $role->load('permissions'),
        ]);
    }

    /**
     * Remove permission from role
     */
    public function removePermission($roleId, $permissionId)
    {
        $role = Role::find($roleId);
        
        if (!$role) {
            return response()->json([
                'success' => false,
                'message' => 'Role not found',
                'message_es' => 'Rol no encontrado',
            ], 404);
        }

        $role->permissions()->detach($permissionId);

        return response()->json([
            'success' => true,
            'message' => 'Permission removed from role successfully',
            'message_es' => 'Permiso removido del rol exitosamente',
            'role' => $role->load('permissions'),
        ]);
    }
}
