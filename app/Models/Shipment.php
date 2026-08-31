<?php

namespace App\Models;

use App\Helpers\TrackingGenerator;
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

    /**
     * Boot the model and auto-generate tracking number if not set
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($shipment) {
            if (empty($shipment->tracking_number)) {
                $shipment->tracking_number = self::generateTrackingNumber($shipment);
            }
        });
    }

    /**
     * Generate tracking number for this shipment
     *
     * @param self $shipment
     * @return string
     */
    protected static function generateTrackingNumber(self $shipment): string
    {
        return TrackingGenerator::generateFromRoute(
            $shipment->origin ?? '',
            $shipment->destination ?? '',
            $shipment->service_type ?? ''
        );
    }

    /**
     * Parse the tracking number and return its components
     *
     * @return array|null
     */
    public function getParsedTracking(): ?array
    {
        return TrackingGenerator::parse($this->tracking_number);
    }

    /**
     * Get tracking URL for QR code
     *
     * @param string|null $baseUrl
     * @return string
     */
    public function getTrackingUrl(?string $baseUrl = null): string
    {
        return TrackingGenerator::getTrackingUrl(
            $this->tracking_number,
            $baseUrl ?? config('app.tracking_url', 'https://track.tr3slog.com')
        );
    }
}
