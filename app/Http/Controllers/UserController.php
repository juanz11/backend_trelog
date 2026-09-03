<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    /**
     * Get all users (admin only)
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', User::class);

        $users = User::with('roles')->get();
        
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
     * Create new user (admin only)
     */
    public function store(Request $request)
    {
        $this->authorize('create', User::class);
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'roles' => 'required|array',
            'roles.*' => 'exists:roles,id',
            'status' => 'required|in:pending,active,suspended',
            'password' => 'sometimes|string|regex:/^(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}$/',
            'phone' => 'nullable|string',
            'business_name' => 'nullable|string',
            'street_address' => 'nullable|string',
            'city' => 'nullable|string',
            'zone' => 'nullable|string',
            'payment_method' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();
            $message = 'Validation failed';
            $messageEs = 'Error de validación';
            
            if ($errors->has('email')) {
                $message = 'This email is already registered';
                $messageEs = 'Este correo ya está registrado';
            }
            
            if ($errors->has('name')) {
                $message = 'Name is required';
                $messageEs = 'El nombre es requerido';
            }
            
            if ($errors->has('roles')) {
                $message = 'Valid roles are required';
                $messageEs = 'Se requieren roles válidos';
            }
            
            return response()->json([
                'success' => false,
                'message' => $message,
                'message_es' => $messageEs,
                'errors' => $errors,
            ], 422);
        }

        $password = $request->password ?? 'temp_password_123';

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($password),
            'status' => $request->status,
            'phone' => $request->phone,
            'business_name' => $request->business_name,
            'street_address' => $request->street_address,
            'city' => $request->city,
            'zone' => $request->zone,
            'payment_method' => $request->payment_method,
        ]);

        // Attach multiple roles
        $user->roles()->attach($request->roles);

        return response()->json([
            'success' => true,
            'user' => $user->load('roles'),
            'message' => 'User created successfully',
        ], 201);
    }

    /**
     * Get specific user
     */
    public function show(Request $request, $id)
    {
        $user = User::with('roles')->find($id);

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
            'password' => 'sometimes|string|regex:/^(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}$/',
            'company' => 'sometimes|nullable|string|max:255',
            'phone' => 'sometimes|nullable|string|max:255',
            'roles' => 'sometimes|array',
            'roles.*' => 'exists:roles,id',
            'status' => 'sometimes|in:active,pending,suspended',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // Only admins can change roles
        if ($request->has('roles') && !$request->user()->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Only admins can change roles',
            ], 403);
        }

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
        if ($request->has('password')) {
            $user->password = Hash::make($request->password);
        }
        if ($request->has('roles') && $request->user()->isAdmin()) {
            $user->roles()->sync($request->roles);
        }
        if ($request->has('status') && $request->user()->isAdmin()) {
            $user->status = $request->status;
        }

        $user->save();

        return response()->json([
            'success' => true,
            'user' => $user->load('roles'),
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
        if (! $request->user()->hasAnyRole(['admin', 'operations']) && ! $request->user()->hasPermission('drivers.manage')) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $driverRole = Role::where('name', 'driver')->first();
        $customerRole = Role::where('name', 'customer')->first();

        $query = User::query();

        if ($customerRole) {
            $query->whereHas('roles', function ($q) use ($customerRole) {
                $q->where('roles.id', $customerRole->id);
            });
        }

        if ($driverRole) {
            $query->whereDoesntHave('roles', function ($q) use ($driverRole) {
                $q->where('roles.id', $driverRole->id);
            });
        }

        $clients = $query->get(['id', 'name', 'email', 'phone']);

        return response()->json($clients);
    }
}
