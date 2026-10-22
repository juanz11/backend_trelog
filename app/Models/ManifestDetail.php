<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManifestDetail extends Model
{
    public const STATUS_PENDIENTE = 'PENDIENTE';
    public const STATUS_ENTREGADO = 'ENTREGADO';
    public const STATUS_NO_ENTREGADO = 'NO_ENTREGADO';
    public const STATUS_TRANSFERIDO = 'TRANSFERIDO';

    public const VIA_QR = 'QR';
    public const VIA_MANUAL = 'MANUAL';

    protected $fillable = [
        'manifest_id', 'shipment_id', 'sequence', 'tracking_code', 'status', 'location',
        'origin_detail_id', 'visited_at', 'failure_reason',
        'evidence_path', 'evidence_url', 'capture_via', 'lat', 'lng', 'gps_accuracy',
    ];

    protected function casts(): array
    {
        return [
            'visited_at' => 'datetime',
            'lat' => 'float',
            'lng' => 'float',
            'gps_accuracy' => 'float',
        ];
    }

    public function manifest(): BelongsTo
    {
        return $this->belongsTo(Manifest::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function originDetail(): BelongsTo
    {
        return $this->belongsTo(ManifestDetail::class, 'origin_detail_id');
    }
}
