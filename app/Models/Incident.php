<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class Incident extends Model
{
    protected $fillable = [
        'driver_id', 'code', 'category', 'title', 'ship',
        'severity', 'status', 'description', 'photo_path',
    ];

    protected $appends = ['photo_url'];

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path
            ? url('storage/' . $this->photo_path)
            : null;
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }
}
