<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManifestHubScan extends Model
{
    public const PHASE_IN = 'in';
    public const PHASE_OUT = 'out';

    protected $fillable = [
        'manifest_id', 'manifest_detail_id', 'tracking_code', 'phase',
        'notes', 'evidence_path', 'scanned_by', 'scanned_at',
    ];

    protected function casts(): array
    {
        return [
            'scanned_at' => 'datetime',
        ];
    }

    public function manifest(): BelongsTo
    {
        return $this->belongsTo(Manifest::class);
    }

    public function detail(): BelongsTo
    {
        return $this->belongsTo(ManifestDetail::class, 'manifest_detail_id');
    }

    public function scanner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by');
    }
}
