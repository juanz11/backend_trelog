<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'user_id',
    'tracking_number',
    'origin',
    'destination',
    'recipient_name',
    'recipient_email',
    'recipient_phone',
    'service_type',
    'weight',
    'dimensions',
    'pieces',
    'status',
    'notes',
])]
class Shipment extends Model
{
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
