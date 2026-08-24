<?php

namespace App\Http\Controllers;

use App\Mail\UserInvitationMail;
use App\Models\UserInvitation;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Carbon\Carbon;

class UserInvitationController extends Controller
{
    /**
     * Send invitation email to a new user
     */
    public function sendInvitation(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
                'name' => 'required|string|max:255',
                'company_name' => 'nullable|string|max:255',
                'inviter_name' => 'nullable|string|max:255',
                'lang' => 'nullable|in:en,es',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Check if invitation already exists for this email
            $existingInvitation = UserInvitation::where('email', $request->email)->first();
            if ($existingInvitation && $existingInvitation->isPending()) {
                return response()->json([
                    'success' => false,
                    'message' => $request->lang === 'en' 
                        ? 'Invitation already sent to this email' 
                        : 'Ya se envió una invitación a este correo',
                ], 400);
            }

            // Generate invitation token
            $token = Str::random(32);
            
            // Create invitation URL
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
            $invitationUrl = $frontendUrl . '/register?token=' . $token;

            // Save invitation to database
            $invitation = UserInvitation::create([
                'email' => $request->email,
                'name' => $request->name,
                'company_name' => $request->company_name ?? 'TR3SLOG',
                'inviter_name' => $request->inviter_name,
                'token' => $token,
                'status' => 'pending',
                'expires_at' => Carbon::now()->addDays(7), // Expires in 7 days
            ]);

            // Send email
            Mail::to($request->email)->send(new UserInvitationMail(
                $request->name,
                $invitationUrl,
                $request->company_name ?? 'TR3SLOG',
                $request->inviter_name,
                $request->lang ?? 'es'
            ));

            return response()->json([
                'success' => true,
                'message' => $request->lang === 'en' 
                    ? 'Invitation sent successfully' 
                    : 'Invitación enviada exitosamente',
                'token' => $token,
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Error sending invitation: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => $request->lang === 'en' 
                    ? 'Failed to send invitation' 
                    : 'Error al enviar la invitación',
                'error' => $e->getMessage(),
                'debug' => config('app.debug') ? $e->getTraceAsString() : null,
            ], 500);
        }
    }

    /**
     * Send bulk invitations
     */
    public function sendBulkInvitations(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'invitations' => 'required|array',
            'invitations.*.email' => 'required|email',
            'invitations.*.name' => 'required|string|max:255',
            'company_name' => 'nullable|string|max:255',
            'inviter_name' => 'nullable|string|max:255',
            'lang' => 'nullable|in:en,es',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $results = [];
        $successCount = 0;
        $failureCount = 0;

        foreach ($request->invitations as $invitation) {
            $token = Str::random(32);
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
            $invitationUrl = $frontendUrl . '/register?token=' . $token;

            try {
                Mail::to($invitation['email'])->send(new UserInvitationMail(
                    $invitation['name'],
                    $invitationUrl,
                    $request->company_name ?? 'TR3SLOG',
                    $request->inviter_name,
                    $request->lang ?? 'es'
                ));

                $results[] = [
                    'email' => $invitation['email'],
                    'success' => true,
                    'token' => $token,
                ];
                $successCount++;

            } catch (\Exception $e) {
                $results[] = [
                    'email' => $invitation['email'],
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
                $failureCount++;
            }
        }

        return response()->json([
            'success' => true,
            'message' => $request->lang === 'en' 
                ? "Sent {$successCount} invitations, {$failureCount} failed" 
                : "Enviadas {$successCount} invitaciones, {$failureCount} fallaron",
            'results' => $results,
            'summary' => [
                'total' => count($request->invitations),
                'success' => $successCount,
                'failed' => $failureCount,
            ],
        ], 200);
    }

    /**
     * Verify invitation token
     */
    public function verifyToken(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $invitation = UserInvitation::where('token', $request->token)->first();

        if (!$invitation) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid invitation token',
            ], 404);
        }

        if ($invitation->isExpired()) {
            return response()->json([
                'success' => false,
                'message' => 'Invitation has expired',
            ], 400);
        }

        if ($invitation->isAccepted()) {
            return response()->json([
                'success' => false,
                'message' => 'Invitation already used',
            ], 400);
        }

        return response()->json([
            'success' => true,
            'invitation' => [
                'email' => $invitation->email,
                'name' => $invitation->name,
            ],
        ]);
    }

    /**
     * Accept invitation and create user
     */
    public function acceptInvitation(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $invitation = UserInvitation::where('token', $request->token)->first();

        if (!$invitation) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid invitation token',
            ], 404);
        }

        if (!$invitation->isPending()) {
            return response()->json([
                'success' => false,
                'message' => 'Invitation is not valid',
            ], 400);
        }

        // Create user with active status
        $user = User::create([
            'name' => $invitation->name,
            'email' => $invitation->email,
            'password' => Hash::make($request->password),
            'status' => 'active',
        ]);

        $customerRole = Role::where('name', 'customer')->first();
        if ($customerRole) {
            $user->roles()->attach($customerRole);
        }

        // Mark invitation as accepted
        $invitation->status = 'accepted';
        $invitation->save();

        return response()->json([
            'success' => true,
            'message' => 'Account created successfully',
            'user' => $user,
        ], 201);
    }

    /**
     * Get pending invitations
     */
    public function getPendingInvitations()
    {
        $invitations = UserInvitation::where('status', 'pending')
            ->where('expires_at', '>', now())
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'invitations' => $invitations,
        ]);
    }

    /**
     * Resend invitation email
     */
    public function resendInvitation(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $invitation = UserInvitation::find($request->id);

        if (!$invitation) {
            return response()->json([
                'success' => false,
                'message' => 'Invitation not found',
            ], 404);
        }

        if (!$invitation->isPending()) {
            return response()->json([
                'success' => false,
                'message' => 'Invitation is not pending',
            ], 400);
        }

        if ($invitation->isExpired()) {
            return response()->json([
                'success' => false,
                'message' => 'Invitation has expired',
            ], 400);
        }

        // Generate new token
        $token = Str::random(32);
        $invitation->token = $token;
        $invitation->save();

        // Create invitation URL
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
        $invitationUrl = $frontendUrl . '/register?token=' . $token;

        // Send email
        try {
            Mail::to($invitation->email)->send(new UserInvitationMail(
                $invitation->name,
                $invitationUrl,
                $invitation->company_name ?? 'TR3SLOG',
                $invitation->inviter_name,
                'es'
            ));

            return response()->json([
                'success' => true,
                'message' => 'Invitation resent successfully',
            ]);
        } catch (\Exception $e) {
            \Log::error('Error resending invitation: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to resend invitation',
            ], 500);
        }
    }
}
