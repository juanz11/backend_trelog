<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PermissionController extends Controller
{
    /**
     * Get all permissions
     */
    public function index()
    {
        $permissions = Permission::with('roles')->get();
        return response()->json([
            'success' => true,
            'permissions' => $permissions,
        ]);
    }

    /**
     * Get a specific permission
     */
    public function show($id)
    {
        $permission = Permission::with('roles')->find($id);
        
        if (!$permission) {
            return response()->json([
                'success' => false,
                'message' => 'Permission not found',
                'message_es' => 'Permiso no encontrado',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'permission' => $permission,
        ]);
    }

    /**
     * Create a new permission
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|unique:permissions',
            'display_name' => 'required|string',
            'description' => 'nullable|string',
            'module' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'message_es' => 'Error de validación',
                'errors' => $validator->errors(),
            ], 422);
        }

        $permission = Permission::create([
            'name' => $request->name,
            'display_name' => $request->display_name,
            'description' => $request->description,
            'module' => $request->module,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Permission created successfully',
            'message_es' => 'Permiso creado exitosamente',
            'permission' => $permission,
        ], 201);
    }

    /**
     * Update a permission
     */
    public function update(Request $request, $id)
    {
        $permission = Permission::find($id);
        
        if (!$permission) {
            return response()->json([
                'success' => false,
                'message' => 'Permission not found',
                'message_es' => 'Permiso no encontrado',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|unique:permissions,name,' . $id,
            'display_name' => 'sometimes|string',
            'description' => 'nullable|string',
            'module' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'message_es' => 'Error de validación',
                'errors' => $validator->errors(),
            ], 422);
        }

        $permission->update($request->only(['name', 'display_name', 'description', 'module']));

        return response()->json([
            'success' => true,
            'message' => 'Permission updated successfully',
            'message_es' => 'Permiso actualizado exitosamente',
            'permission' => $permission->load('roles'),
        ]);
    }

    /**
     * Delete a permission
     */
    public function destroy($id)
    {
        $permission = Permission::find($id);
        
        if (!$permission) {
            return response()->json([
                'success' => false,
                'message' => 'Permission not found',
                'message_es' => 'Permiso no encontrado',
            ], 404);
        }

        // Detach from roles before deleting
        $permission->roles()->detach();
        $permission->delete();

        return response()->json([
            'success' => true,
            'message' => 'Permission deleted successfully',
            'message_es' => 'Permiso eliminado exitosamente',
        ]);
    }

    /**
     * Get permissions by module
     */
    public function getByModule($module)
    {
        $permissions = Permission::where('module', $module)->with('roles')->get();
        
        return response()->json([
            'success' => true,
            'permissions' => $permissions,
        ]);
    }
}
