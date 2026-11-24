<?php

namespace App\Http\Controllers;

use App\Mail\UserInvitationMail;
use App\Models\CompanyMember;
use App\Models\Contract;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\CompanyContractService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

/**
 * Executive-client self-service: contract quota state, contract requests
 * (pending admin approval) and team management (agent invitations).
 */
class CompanyController extends Controller
{
    public function __construct(private CompanyContractService $service)
    {
    }

    /** Mi empresa + estado del contrato + equipo (para el portal ejecutivo). */
    public function show(Request $request): JsonResponse
    {
        $state = $this->service->stateFor($request->user());
        $company = $state['company'];
        if (! $company) {
            return response()->json(['company' => null, 'contract' => null, 'team' => [], 'pending_invitations' => []]);
        }

        $members = CompanyMember::with('user:id,name,email,phone')
            ->where('company_id', $company->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (CompanyMember $m) => [
                'id' => $m->id,
                'user_id' => $m->user_id,
                'name' => $m->user?->name,
                'email' => $m->user?->email,
                'role' => $m->role,
                'status' => $m->status,
                'is_me' => $m->user_id === $request->user()->id,
            ]);

        $pendingInvites = UserInvitation::where('company_id', $company->id)
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->get(['id', 'email', 'name', 'status', 'expires_at', 'created_at']);

        $contract = $state['contract'] ?? null;
        return response()->json([
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'tax_id' => $company->tax_id,
                'status' => $company->status,
            ],
            'contract' => $contract ? [
                'id' => $contract->id,
                'code' => $contract->code,
                'name' => $contract->name,
                'status' => $contract->status,
                'requested_quota' => $contract->requested_quota,
                'notes' => $contract->notes,
            ] : null,
            'state' => $state['mode'],
            'usage' => $state['usage'] ?? null,
            'team' => $members,
            'pending_invitations' => $pendingInvites,
        ]);
    }

    /**
     * El ejecutivo solicita (o amplía) el cupo del contrato.
     * Crea la empresa si no existe y deja un contrato en estado pending
     * que el admin debe aprobar.
     */
    public function requestContract(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasAnyRole(['executive_client', 'company'])) {
            return response()->json(['success' => false, 'message' => 'Solo el cliente ejecutivo puede solicitar contratos.'], 403);
        }

        $data = $request->validate([
            'quota' => 'required|integer|min:1|max:100000',
            'name' => 'nullable|string|max:255',
            'ends_at' => 'nullable|date|after:today',
            'notes' => 'nullable|string|max:2000',
            'company_name' => 'nullable|string|max:255',
        ]);

        $state = $this->service->stateFor($user);
        $company = $state['company'] ?: $this->service->ensureCompanyFor($user, $data['company_name'] ?? null);

        $pending = $company->pendingContract();
        if ($pending) {
            return response()->json(['success' => false, 'message' => 'Ya tienes una solicitud de contrato pendiente de aprobación.'], 409);
        }

        $contract = Contract::create([
            'company_id' => $company->id,
            'code' => $this->service->generateCode(),
            'name' => $data['name'] ?? null,
            'shipment_quota' => 0,
            'requested_quota' => $data['quota'],
            'starts_at' => now()->toDateString(),
            'ends_at' => $data['ends_at'] ?? null,
            'status' => 'pending',
            'requested_by_user_id' => $user->id,
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json(['success' => true, 'contract' => $contract, 'message' => 'Solicitud enviada. El administrador la revisará.'], 201);
    }

    /** Invita un agente de envíos por email. */
    public function invite(Request $request): JsonResponse
    {
        $user = $request->user();
        $state = $this->service->stateFor($user);
        $company = $state['company'];
        $member = $this->service->membership($user);

        if (! $company || ! $member || $member->role !== CompanyMember::ROLE_OWNER) {
            return response()->json(['success' => false, 'message' => 'Solo el propietario puede invitar miembros.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'email' => 'required|email|max:255',
            'name' => 'required|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $email = strtolower(trim($request->email));

        if (User::where('email', $email)->exists()) {
            return response()->json(['success' => false, 'message' => 'Ese correo ya tiene una cuenta en TR3SLOG.'], 409);
        }
        if (UserInvitation::where('email', $email)->where('status', 'pending')->exists()) {
            return response()->json(['success' => false, 'message' => 'Ya hay una invitación pendiente para ese correo.'], 409);
        }
        if (CompanyMember::whereHas('user', fn ($q) => $q->where('email', $email))->exists()) {
            return response()->json(['success' => false, 'message' => 'Ese usuario ya pertenece a una empresa.'], 409);
        }

        $token = Str::random(32);
        UserInvitation::create([
            'email' => $email,
            'name' => $request->name,
            'company_name' => $company->name,
            'inviter_name' => $user->name,
            'token' => $token,
            'status' => 'pending',
            'expires_at' => Carbon::now()->addDays(7),
            'company_id' => $company->id,
            'member_role' => CompanyMember::ROLE_AGENT,
        ]);

        $frontendUrl = rtrim(config('app.frontend_url') ?: 'http://localhost:3000', '/');
        try {
            Mail::to($email)->send(new UserInvitationMail(
                $request->name,
                $frontendUrl . '/register?token=' . $token,
                $company->name,
                $user->name,
                'es'
            ));
        } catch (\Exception $e) {
            \Log::error('Company invite mail failed: ' . $e->getMessage());
        }

        return response()->json(['success' => true, 'message' => 'Invitación enviada.'], 201);
    }

    /** Suspende/reactiva un miembro del equipo. */
    public function setMemberStatus(Request $request, CompanyMember $member): JsonResponse
    {
        $user = $request->user();
        $owner = $this->service->membership($user);

        if (! $owner || $owner->role !== CompanyMember::ROLE_OWNER || $owner->company_id !== $member->company_id) {
            return response()->json(['success' => false, 'message' => 'forbidden'], 403);
        }
        if ($member->role === CompanyMember::ROLE_OWNER || $member->user_id === $user->id) {
            return response()->json(['success' => false, 'message' => 'No puedes modificar al propietario.'], 422);
        }

        $status = $request->validate(['status' => 'required|in:active,suspended'])['status'];
        $member->update(['status' => $status]);

        return response()->json(['success' => true, 'member' => $member]);
    }

    /** Quita el acceso de un miembro (no borra su cuenta). */
    public function removeMember(Request $request, CompanyMember $member): JsonResponse
    {
        $user = $request->user();
        $owner = $this->service->membership($user);

        if (! $owner || $owner->role !== CompanyMember::ROLE_OWNER || $owner->company_id !== $member->company_id) {
            return response()->json(['success' => false, 'message' => 'forbidden'], 403);
        }
        if ($member->role === CompanyMember::ROLE_OWNER || $member->user_id === $user->id) {
            return response()->json(['success' => false, 'message' => 'No puedes quitar al propietario.'], 422);
        }

        DB::transaction(function () use ($member) {
            $member->user()?->syncRoles(['customer']);
            $member->delete();
        });

        return response()->json(['success' => true]);
    }

    /** Cancela una invitación pendiente del equipo. */
    public function cancelInvitation(Request $request, UserInvitation $invitation): JsonResponse
    {
        $user = $request->user();
        $owner = $this->service->membership($user);

        if (! $owner || $owner->role !== CompanyMember::ROLE_OWNER || $invitation->company_id !== $owner->company_id) {
            return response()->json(['success' => false, 'message' => 'forbidden'], 403);
        }
        if (! $invitation->isPending()) {
            return response()->json(['success' => false, 'message' => 'La invitación ya no está pendiente.'], 400);
        }

        $invitation->status = 'expired';
        $invitation->save();

        return response()->json(['success' => true]);
    }
}
