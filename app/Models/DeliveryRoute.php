<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryRoute extends Model
{
    protected $table = 'routes';

    protected $fillable = [
        'driver_id', 'code', 'date_label', 'stops_count',
        'duration', 'vehicle', 'status', 'progress', 'instructions',
        'close_notes', 'close_photo_path', 'close_signature_path',
        'close_received_by', 'close_quantity', 'close_condition',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'progress' => 'float',
            'completed_at' => 'datetime',
        ];
    }

    protected $appends = ['close_photo_url', 'close_signature_url'];

    public function getClosePhotoUrlAttribute(): ?string
    {
        return $this->close_photo_path
            ? url('storage/' . $this->close_photo_path)
            : null;
    }

    public function getCloseSignatureUrlAttribute(): ?string
    {
        return $this->close_signature_path
            ? url('storage/' . $this->close_signature_path)
            : null;
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function stops(): HasMany
    {
        return $this->hasMany(RouteStop::class, 'route_id')->orderBy('n');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(RouteAuditLog::class, 'route_id')->latest();
    }
}
