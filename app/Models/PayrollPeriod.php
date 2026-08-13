<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollPeriod extends Model
{
    protected $fillable = [
        'driver_id', 'period_label', 'status', 'base',
        'bonuses', 'deductions', 'paid_on',
    ];

    protected function casts(): array
    {
        return [
            'base' => 'decimal:2',
            'bonuses' => 'decimal:2',
            'deductions' => 'decimal:2',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }
}
