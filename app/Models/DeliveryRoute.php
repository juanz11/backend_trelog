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
    ];

    protected function casts(): array
    {
        return [
            'progress' => 'float',
        ];
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
