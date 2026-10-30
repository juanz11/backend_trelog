<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManifestAdjustment extends Model
{
    public const TYPE_FALTANTE = 'FALTANTE';
    public const TYPE_SOBRANTE = 'SOBRANTE';
    public const TYPE_AVERIADO = 'AVERIADO';
    public const TYPE_OTRO = 'OTRO';

    public const STATUS_PENDIENTE = 'PENDIENTE';
    public const STATUS_APROBADO = 'APROBADO';
    public const STATUS_RECHAZADO = 'RECHAZADO';

    protected $fillable = [
        'manifest_id', 'tracking_code', 'type', 'reason', 'status',
        'expected_value', 'actual_value',
        'evidence_path', 'registered_by', 'resolved_by',
        'resolution_reason', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    public function manifest(): BelongsTo
    {
        return $this->belongsTo(Manifest::class);
    }

    public function registrar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
