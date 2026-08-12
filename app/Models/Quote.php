<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Quote extends Model
{
    use HasFactory;

    protected $fillable = [
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
    ];

    protected $casts = [
        'viewed_at' => 'datetime',
    ];
}
