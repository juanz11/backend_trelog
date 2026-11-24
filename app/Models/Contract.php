<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contract extends Model
{
    /** Envíos cancelados no consumen cupo del contrato. */
    public const CANCELLED_STATUSES = ['cancelled', 'cancelado'];

    protected $fillable = [
        'company_id', 'code', 'name', 'shipment_quota', 'rate_per_shipment',
        'starts_at', 'ends_at', 'status',
        'requested_quota', 'requested_by_user_id', 'approved_by_user_id', 'approved_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'shipment_quota' => 'integer',
            'requested_quota' => 'integer',
            'rate_per_shipment' => 'decimal:2',
            'starts_at' => 'date',
            'ends_at' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /** Envíos que consumen cupo: los creados bajo contrato que no fueron cancelados. */
    public function usedCount(): int
    {
        return $this->shipments()
            ->whereNotIn('status', self::CANCELLED_STATUSES)
            ->count();
    }

    public function remaining(): int
    {
        return max(0, (int) $this->shipment_quota - $this->usedCount());
    }

    public function isExpired(): bool
    {
        return $this->ends_at && $this->ends_at->lt(now()->startOfDay());
    }

    /** Cupo realmente disponible (0 si vencido o suspendido). */
    public function effectiveRemaining(): int
    {
        if ($this->status !== 'active' || $this->isExpired()) {
            return 0;
        }
        return $this->remaining();
    }
}
