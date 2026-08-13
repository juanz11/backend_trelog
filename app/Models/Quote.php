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
])]
class Quote extends Model
{
    protected function casts(): array
    {
        return [
            'viewed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
