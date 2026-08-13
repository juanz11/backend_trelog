<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'origin',
    'destination',
    'service_type',
    'weight',
    'dimensions',
    'pieces',
    'client_name',
    'client_email',
    'details',
    'status',
    'viewed_at',
    'tracking_code',
])]
class Quote extends Model
{
    protected $appends = ['estimated_delivery'];

    protected function casts(): array
    {
        return [
            'viewed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function getEstimatedDeliveryAttribute(): string
    {
        $days = match ($this->service_type) {
            'same-day' => 0,
            'express' => 1,
            'standard' => 2,
            default => 1,
        };

        return now()->addDays($days)->setTime(16, 30, 0)->toIso8601String();
    }
}
