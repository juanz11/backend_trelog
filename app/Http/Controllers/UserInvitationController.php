<?php

namespace App\Http\Controllers;

use App\Mail\UserInvitationMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

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

            // Generate invitation token
            $token = Str::random(32);
            
            // Create invitation URL (you should store this in database in production)
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:5173');
            $invitationUrl = $frontendUrl . '/register?token=' . $token;

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
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:5173');
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
}
