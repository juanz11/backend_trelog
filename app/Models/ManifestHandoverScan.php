<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManifestHandoverScan extends Model
{
    public const PHASE_OUT = 'out';
    public const PHASE_IN = 'in';

    protected $fillable = [
        'manifest_id', 'tracking_code', 'phase', 'scanned_by', 'lat', 'lng', 'scanned_at',
    ];

    protected function casts(): array
    {
        return [
            'scanned_at' => 'datetime',
            'lat' => 'float',
            'lng' => 'float',
        ];
    }

    public function manifest(): BelongsTo
    {
        return $this->belongsTo(Manifest::class);
    }

    public function scanner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by');
    }
}
