<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Zone extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
        'delivery_types',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
