<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'shipment_id',
    'type',
    'pieces',
    'weight',
    'weight_unit',
    'length_cm',
    'width_cm',
    'height_cm',
    'volume_m3',
    'declared_value',
    'content',
])]
class ShipmentPackage extends Model
{
    /**
     * Standard dimensional-weight divisor used by ground/air couriers (cm³ per kg).
     */
    public const DIM_FACTOR_CM3_PER_KG = 5000;

    protected function casts(): array
    {
        return [
            'pieces' => 'integer',
            'weight' => 'float',
            'length_cm' => 'float',
            'width_cm' => 'float',
            'height_cm' => 'float',
            'volume_m3' => 'float',
            'declared_value' => 'float',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /**
     * Volumetric (dimensional) weight in kg: L × W × H (cm) / 5000.
     */
    public function getVolumetricWeightKgAttribute(): ?float
    {
        if (! $this->length_cm || ! $this->width_cm || ! $this->height_cm) {
            return null;
        }

        return round($this->length_cm * $this->width_cm * $this->height_cm / self::DIM_FACTOR_CM3_PER_KG, 3);
    }

    /**
     * Chargeable weight in kg: the greater of real weight and volumetric weight.
     * Returns null when neither is available.
     */
    public function getChargeableWeightKgAttribute(): ?float
    {
        $real = $this->weight;
        if ($this->weight_unit === 'lb' && $real !== null) {
            $real = $real * 0.453592;
        }
        $vol = $this->volumetric_weight_kg;

        if ($real === null && $vol === null) {
            return null;
        }

        return round(max($real ?? 0, $vol ?? 0), 3);
    }

    /**
     * Compute the volume in m³ from centimetre dimensions.
     */
    public static function volumeM3(?float $length, ?float $width, ?float $height): ?float
    {
        if (! $length || ! $width || ! $height) {
            return null;
        }

        return round($length * $width * $height / 1_000_000, 6);
    }
}
