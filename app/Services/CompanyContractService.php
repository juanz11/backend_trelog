<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Resolves the contract-company context for a user (executive owner or
 * shipment agent) and summarizes quota usage for the API/UI.
 */
class CompanyContractService
{
    public function membership(User $user): ?CompanyMember
    {
        return $user->companyMember;
    }

    public function company(User $user): ?Company
    {
        return $user->company();
    }

    /**
     * Contract state for the user's company.
     * mode: none | request | active | exhausted | expired | suspended
     */
    public function stateFor(User $user): array
    {
        $member = $this->membership($user);
        if (! $member || ! $member->isActive()) {
            return ['mode' => 'none', 'company' => null, 'contract' => null];
        }

        $company = $member->company;
        $contract = $company?->currentContract();

        if (! $contract) {
            $pending = $company?->pendingContract();
            return [
                'mode' => $pending ? 'request' : 'none',
                'company' => $company,
                'contract' => $pending,
                'pending' => $pending,
            ];
        }

        $used = $contract->usedCount();
        $remaining = max(0, (int) $contract->shipment_quota - $used);
        $daysLeft = $contract->ends_at ? (int) now()->startOfDay()->diffInDays($contract->ends_at, false) : null;

        if ($contract->isExpired() || ($contract->ends_at && $daysLeft !== null && $daysLeft < 0)) {
            $mode = 'expired';
        } elseif ($contract->status === 'suspended') {
            $mode = 'suspended';
        } elseif ($remaining <= 0) {
            $mode = 'exhausted';
        } else {
            $mode = 'active';
        }

        return [
            'mode' => $mode,
            'company' => $company,
            'contract' => $contract,
            'usage' => [
                'quota' => (int) $contract->shipment_quota,
                'used' => $used,
                'remaining' => $remaining,
                'ends_at' => $contract->ends_at?->toDateString(),
                'days_left' => $daysLeft,
                'code' => $contract->code,
                'status' => $contract->status,
            ],
        ];
    }

    /** Creates (or reuses) the company owned by this executive user. */
    public function ensureCompanyFor(User $user, ?string $name = null): Company
    {
        $existing = $this->membership($user)?->company;
        if ($existing) {
            return $existing;
        }

        $company = Company::create([
            'name' => $name ?: ($user->company ?: $user->business_name ?: $user->name),
            'owner_user_id' => $user->id,
        ]);

        CompanyMember::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role' => CompanyMember::ROLE_OWNER,
            'status' => 'active',
        ]);

        return $company;
    }

    public function generateCode(): string
    {
        do {
            $code = 'CTR-' . now()->format('ym') . '-' . strtoupper(Str::random(6));
        } while (Contract::where('code', $code)->exists());

        return $code;
    }
}
