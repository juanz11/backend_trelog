<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyMember extends Model
{
    public const ROLE_OWNER = 'owner';
    public const ROLE_AGENT = 'agent';

    protected $fillable = [
        'company_id', 'user_id', 'role', 'status', 'invited_by',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->company?->status === 'active';
    }
}
