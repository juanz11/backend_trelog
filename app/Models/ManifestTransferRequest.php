<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManifestTransferRequest extends Model
{
    public const STATUS_PENDIENTE = 'PENDIENTE';
    public const STATUS_APROBADA = 'APROBADA';
    public const STATUS_RECHAZADA = 'RECHAZADA';

    protected $fillable = [
        'manifest_id', 'reason', 'status', 'requested_by', 'reviewed_by',
        'resolution_reason', 'resolved_at', 'detail_ids',
    ];

    protected function casts(): array
    {
        return [
            'detail_ids' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function manifest(): BelongsTo
    {
        return $this->belongsTo(Manifest::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
