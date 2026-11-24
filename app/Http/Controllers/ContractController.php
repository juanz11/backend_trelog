<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\Contract;
use App\Services\CompanyContractService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Admin/finance management of contract companies and their shipment quotas.
 */
class ContractController extends Controller
{
    public function __construct(private CompanyContractService $service)
    {
    }

    /** Empresas con su contrato actual, uso de cupo y solicitudes pendientes. */
    public function index(): JsonResponse
    {
        $companies = Company::with(['owner:id,name,email', 'members.user:id,name,email'])
            ->withCount('contracts')
            ->orderByDesc('created_at')
            ->get();

        $contracts = Contract::with(['company:id,name', 'requestedBy:id,name', 'approvedBy:id,name'])
            ->orderByDesc('created_at')
            ->get();

        $byCompany = $contracts->groupBy('company_id');

        return response()->json([
            'companies' => $companies->map(fn (Company $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'tax_id' => $c->tax_id,
                'status' => $c->status,
                'owner' => $c->owner ? ['id' => $c->owner->id, 'name' => $c->owner->name, 'email' => $c->owner->email] : null,
                'members_count' => $c->members->count(),
                'contracts_count' => $c->contracts_count,
            ]),
            'contracts' => $contracts->map(fn (Contract $ct) => $this->serialize($ct)),
        ]);
    }

    private function serialize(Contract $ct): array
    {
        $used = $ct->usedCount();
        return [
            'id' => $ct->id,
            'company_id' => $ct->company_id,
            'company' => $ct->company?->only(['id', 'name']),
            'code' => $ct->code,
            'name' => $ct->name,
            'shipment_quota' => (int) $ct->shipment_quota,
            'requested_quota' => $ct->requested_quota,
            'rate_per_shipment' => $ct->rate_per_shipment,
            'starts_at' => $ct->starts_at?->toDateString(),
            'ends_at' => $ct->ends_at?->toDateString(),
            'status' => $ct->status,
            'used' => $used,
            'remaining' => max(0, (int) $ct->shipment_quota - $used),
            'expired' => $ct->isExpired(),
            'requested_by' => $ct->requestedBy?->only(['id', 'name', 'email']),
            'approved_by' => $ct->approvedBy?->only(['id', 'name']),
            'approved_at' => $ct->approved_at?->toISOString(),
            'notes' => $ct->notes,
            'created_at' => $ct->created_at?->toISOString(),
        ];
    }

    /** Crear empresa + contrato activo directamente (alta por admin). */
    public function store(Request $request): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'company_name' => 'required|string|max:255',
            'tax_id' => 'nullable|string|max:50',
            'owner_email' => 'nullable|email|max:255',
            'contract_name' => 'nullable|string|max:255',
            'shipment_quota' => 'required|integer|min:1|max:100000',
            'rate_per_shipment' => 'nullable|numeric|min:0',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'notes' => 'nullable|string|max:2000',
        ])->validate();

        $owner = null;
        if (! empty($data['owner_email'])) {
            $owner = \App\Models\User::where('email', $data['owner_email'])->first();
            if (! $owner) {
                return response()->json(['success' => false, 'message' => 'No existe un usuario con ese correo. Créelo primero en Usuarios.'], 422);
            }
            if ($owner->companyMember) {
                return response()->json(['success' => false, 'message' => 'Ese usuario ya pertenece a una empresa.'], 409);
            }
        }

        $company = Company::create([
            'name' => $data['company_name'],
            'tax_id' => $data['tax_id'] ?? null,
            'owner_user_id' => $owner?->id,
        ]);

        if ($owner) {
            CompanyMember::create([
                'company_id' => $company->id,
                'user_id' => $owner->id,
                'role' => CompanyMember::ROLE_OWNER,
                'status' => 'active',
            ]);
        }

        $contract = Contract::create([
            'company_id' => $company->id,
            'code' => $this->service->generateCode(),
            'name' => $data['contract_name'] ?? null,
            'shipment_quota' => $data['shipment_quota'],
            'rate_per_shipment' => $data['rate_per_shipment'] ?? null,
            'starts_at' => $data['starts_at'] ?? now()->toDateString(),
            'ends_at' => $data['ends_at'] ?? null,
            'status' => 'active',
            'approved_by_user_id' => $request->user()->id,
            'approved_at' => now(),
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json(['success' => true, 'company' => $company, 'contract' => $this->serialize($contract->fresh(['company', 'requestedBy', 'approvedBy']))], 201);
    }

    /** Editar cupo, fechas, tarifa o estado de un contrato. */
    public function update(Request $request, Contract $contract): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'shipment_quota' => 'nullable|integer|min:1|max:100000',
            'rate_per_shipment' => 'nullable|numeric|min:0',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date',
            'status' => 'nullable|in:active,suspended,cancelled',
            'notes' => 'nullable|string|max:2000',
        ])->validate();

        $contract->update($data);

        return response()->json(['success' => true, 'contract' => $this->serialize($contract->fresh(['company', 'requestedBy', 'approvedBy']))]);
    }

    /** Aprobar una solicitud del ejecutivo (pending → active). */
    public function approve(Request $request, Contract $contract): JsonResponse
    {
        if ($contract->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'El contrato no está pendiente.'], 422);
        }

        $data = Validator::make($request->all(), [
            'shipment_quota' => 'nullable|integer|min:1|max:100000',
            'rate_per_shipment' => 'nullable|numeric|min:0',
            'ends_at' => 'nullable|date',
        ])->validate();

        $contract->update([
            'status' => 'active',
            'shipment_quota' => $data['shipment_quota'] ?? $contract->requested_quota ?? 0,
            'rate_per_shipment' => $data['rate_per_shipment'] ?? $contract->rate_per_shipment,
            'ends_at' => $data['ends_at'] ?? $contract->ends_at,
            'approved_by_user_id' => $request->user()->id,
            'approved_at' => now(),
        ]);

        if ($contract->shipment_quota < 1) {
            return response()->json(['success' => false, 'message' => 'Debes indicar el cupo aprobado (envíos).'], 422);
        }

        return response()->json(['success' => true, 'contract' => $this->serialize($contract->fresh(['company', 'requestedBy', 'approvedBy']))]);
    }

    /** Rechazar/cancelar una solicitud pendiente. */
    public function reject(Request $request, Contract $contract): JsonResponse
    {
        if ($contract->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'El contrato no está pendiente.'], 422);
        }

        $contract->update(['status' => 'cancelled']);

        return response()->json(['success' => true]);
    }

    /** Agregar cupo extra a un contrato activo (ampliación). */
    public function addQuota(Request $request, Contract $contract): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'extra' => 'required|integer|min:1|max:100000',
        ])->validate();

        $contract->increment('shipment_quota', $data['extra']);

        return response()->json(['success' => true, 'contract' => $this->serialize($contract->fresh(['company', 'requestedBy', 'approvedBy']))]);
    }
}
