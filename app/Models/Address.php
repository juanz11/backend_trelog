<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'type',
    'name',
    'address',
    'city',
    'zip_code',
    'contact_name',
    'phone',
    'delivery_instructions',
    'is_default',
])]
class Address extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
