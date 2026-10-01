<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['dim_divisor', 'service_limits', 'service_rates'])]
class PricingConfig extends Model
{
    public const DEFAULTS = [
        'dim_divisor' => 166,
        'service_limits' => [
            'Terrestre' => 130,
            'Consolidado' => 130,
            'Marítimo' => 150,
            'Aéreo' => 108,
            'Última milla' => 130,
        ],
        'service_rates' => [
            'Terrestre' => ['base' => 5, 'per_lb' => 0.75],
            'Consolidado' => ['base' => 8, 'per_lb' => 0.65],
            'Marítimo' => ['base' => 15, 'per_lb' => 0.45],
            'Aéreo' => ['base' => 12, 'per_lb' => 1.60],
            'Última milla' => ['base' => 4, 'per_lb' => 0.90],
        ],
    ];

    protected function casts(): array
    {
        return [
            'dim_divisor' => 'float',
            'service_limits' => 'array',
            'service_rates' => 'array',
        ];
    }

    public static function current(): self
    {
        return self::first() ?? self::create(self::DEFAULTS);
    }
}
