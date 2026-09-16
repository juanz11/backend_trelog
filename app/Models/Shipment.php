<?php

namespace App\Models;

use App\Helpers\TrackingGenerator;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'user_id',
    'driver_id',
    'assigned_at',
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
    'packages',
    'status',
    'notes',
])]
class Shipment extends Model
{
    protected function casts(): array
    {
        return [
            'packages' => 'array',
            'assigned_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function stop(): HasOne
    {
        return $this->hasOne(RouteStop::class, 'shipment_id');
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
        return $this->tracking_number ? TrackingGenerator::parse($this->tracking_number) : null;
    }

    /**
     * Get tracking URL for QR code
     *
     * @param string|null $baseUrl
     * @return string
     */
    public function getTrackingUrl(?string $baseUrl = null): string
    {
        if (!$this->tracking_number) {
            return '';
        }
        return TrackingGenerator::getTrackingUrl(
            $this->tracking_number,
            $baseUrl ?? config('app.tracking_url', 'https://track.tr3slog.com')
        );
    }
}
