<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    protected $fillable = [
        'name', 'tax_id', 'contact_name', 'email', 'phone', 'owner_user_id', 'status',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(CompanyMember::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class)->latest();
    }

    /** Contrato en estado usable (activo o suspendido), el más reciente. */
    public function currentContract(): ?Contract
    {
        return $this->contracts()
            ->whereIn('status', ['active', 'suspended'])
            ->latest()
            ->first();
    }

    /** Contrato activo vigente (la expiración por fecha se evalúa en el servicio). */
    public function activeContract(): ?Contract
    {
        $contract = $this->currentContract();
        return $contract && $contract->status === 'active' ? $contract : null;
    }

    public function pendingContract(): ?Contract
    {
        return $this->contracts()->where('status', 'pending')->latest()->first();
    }
}
