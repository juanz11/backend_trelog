<?php

namespace App\Helpers;

use Carbon\Carbon;

class TrackingGenerator
{
    // Carrier Code
    const CARRIER_CODE = 'TR3S';

    // Package Type Codes
    const TYPE_AIR = 'A';          // Air Express
    const TYPE_GROUND = 'G';       // Ground
    const TYPE_OCEAN = 'O';        // Ocean Freight
    const TYPE_COURIER = 'C';      // Courier
    const TYPE_FREIGHT = 'F';      // Freight
    const TYPE_WAREHOUSE = 'W';    // Warehouse Transfer
    const TYPE_RETURN = 'R';       // Return Shipment

    // Location Codes (Origin Hub)
    const LOCATIONS = [
        'PRSJ' => 'Puerto Rico - San Juan',
        'DOSD' => 'Dominican Republic - Santo Domingo',
        'DOPC' => 'Dominican Republic - Punta Cana',
        'MIAM' => 'Miami',
        'NYNY' => 'New York',
        'ATLG' => 'Atlanta',
        'JAMB' => 'Montego Bay',
        'KRSE' => 'Seoul',
        'JPTY' => 'Tokyo',
        'CNSH' => 'Shanghai',
        'VECC' => 'Venezuela - Caracas',
        'VEVL' => 'Venezuela - Valencia',
        'VEMA' => 'Venezuela - Maracaibo',
        'UYMV' => 'Uruguay - Montevideo',
        'UYPE' => 'Uruguay - Punta del Este',
        'UYPA' => 'Uruguay - Paysandú',
        'UYSA' => 'Uruguay - Salto',
        'UYCO' => 'Uruguay - Colonia',
    ];

    // Package Type Labels
    public static array $typeLabels = [
        'A' => 'Air Express',
        'G' => 'Ground',
        'O' => 'Ocean Freight',
        'C' => 'Courier',
        'F' => 'Freight',
        'W' => 'Warehouse Transfer',
        'R' => 'Return Shipment',
    ];

    /**
     * Generate a tracking number in format: TR3S-DDMMYY-LOC-TYPE######
     *
     * @param string $locationCode 4-character location code
     * @param string $packageType Package type code (A, G, O, C, F, W, R)
     * @param int $sequence Sequential package number (default: auto-increment)
     * @param Carbon|null $date Ship date (default: today)
     * @return string
     */
    public static function generate(
        string $locationCode,
        string $packageType,
        int $sequence = null,
        ?Carbon $date = null
    ): string {
        $date = $date ?? Carbon::now();

        // Format date as DDMMYY
        $datePart = $date->format('dmy');

        // Pad sequence to 6 digits
        $sequence = $sequence ?? self::getNextSequence($date, $locationCode, $packageType);
        $sequencePart = str_pad($sequence, 6, '0', STR_PAD_LEFT);

        // Build tracking number
        return sprintf('%s-%s-%s-%s%s',
            self::CARRIER_CODE,
            $datePart,
            strtoupper($locationCode),
            strtoupper($packageType),
            $sequencePart
        );
   }

    /**
     * Generate a tracking number from origin and service strings (used by quotes & shipments)
     *
     * @param string $origin  City / country text
     * @param string $serviceType  Service description
     * @param Carbon|null $date
     * @return string
     */
    public static function generateFromOrigin(string $origin, string $serviceType = '', ?Carbon $date = null): string
    {
        $locationCode = self::getLocationCode($origin);
        $packageType = self::getPackageType($serviceType);
        return self::generate($locationCode, $packageType, null, $date);
    }

    /**
     * Get location code from free-form origin string
     *
     * @param string $origin
     * @return string
     */
    public static function getLocationCode(string $origin): string
    {
        $originLower = strtolower($origin);

        // Search cities first (more specific), then countries
        $cityMap = [
            'san juan' => 'PRSJ',
            'santo domingo' => 'DOSD',
            'punta cana' => 'DOPC',
            'miami' => 'MIAM',
            'new york' => 'NYNY',
            'atlanta' => 'ATLG',
            'montego bay' => 'JAMB',
            'seoul' => 'KRSE',
            'tokyo' => 'JPTY',
            'shanghai' => 'CNSH',
            'caracas' => 'VECC',
            'valencia' => 'VEVL',
            'maracaibo' => 'VEMA',
            'montevideo' => 'UYMV',
            'punta del este' => 'UYPE',
            'paysandú' => 'UYPA',
            'paysandu' => 'UYPA',
            'salto' => 'UYSA',
            'colonia' => 'UYCO',
        ];

        foreach ($cityMap as $key => $code) {
            if (str_contains($originLower, $key)) {
                return $code;
            }
        }

        $countryMap = [
            'puerto rico' => 'PRSJ',
            'dominican republic' => 'DOSD',
            'venezuela' => 'VECC',
            'uruguay' => 'UYMV',
        ];

        foreach ($countryMap as $key => $code) {
            if (str_contains($originLower, $key)) {
                return $code;
            }
        }

        // Default to San Juan if not found
        return 'PRSJ';
    }

    /**
     * Get package type code from free-form service string
     *
     * @param string $serviceType
     * @return string
     */
    public static function getPackageType(string $serviceType): string
    {
        $serviceLower = strtolower($serviceType);

        $typeMap = [
            'air' => self::TYPE_AIR,
            'express' => self::TYPE_AIR,
            'ground' => self::TYPE_GROUND,
            'terrestre' => self::TYPE_GROUND,
            'ocean' => self::TYPE_OCEAN,
            'marítimo' => self::TYPE_OCEAN,
            'maritimo' => self::TYPE_OCEAN,
            'freight' => self::TYPE_FREIGHT,
            'courier' => self::TYPE_COURIER,
            'warehouse' => self::TYPE_WAREHOUSE,
            'almacenamiento' => self::TYPE_WAREHOUSE,
            'return' => self::TYPE_RETURN,
            'última milla' => self::TYPE_COURIER,
            'ultima milla' => self::TYPE_COURIER,
            'last mile' => self::TYPE_COURIER,
        ];

        foreach ($typeMap as $key => $code) {
            if (str_contains($serviceLower, $key)) {
                return $code;
            }
        }

        // Default to Ground
        return self::TYPE_GROUND;
    }

    /**
     * Get the next sequence number for a given date, location, and package type
     *
     * @param Carbon $date
     * @param string $locationCode
     * @param string $packageType
     * @return int
     */
    protected static function getNextSequence(Carbon $date, string $locationCode, string $packageType): int
    {
        // Generate the prefix for this combination (TR3S-DDMMYY-LOC-TYPE)
        $prefix = sprintf('%s-%s-%s-%s',
            self::CARRIER_CODE,
            $date->format('dmy'),
            strtoupper($locationCode),
            strtoupper($packageType)
        );

        // Query both shipments and quotes for the last tracking number with this prefix
        $lastShipment = \App\Models\Shipment::where('tracking_number', 'like', $prefix . '%')
            ->orderBy('tracking_number', 'desc')
            ->first();

        $lastQuote = \App\Models\Quote::where('tracking_code', 'like', $prefix . '%')
            ->orderBy('tracking_code', 'desc')
            ->first();

        $lastShipmentSeq = $lastShipment ? (int)substr($lastShipment->tracking_number, -6) : 0;
        $lastQuoteSeq = $lastQuote ? (int)substr($lastQuote->tracking_code, -6) : 0;

        return max($lastShipmentSeq, $lastQuoteSeq) + 1;
    }

    /**
     * Validate a tracking number format
     *
     * @param string $trackingNumber
     * @return bool
     */
    public static function validate(string $trackingNumber): bool
    {
        $pattern = '/^' . self::CARRIER_CODE . '-\d{6}-[A-Z]{4}-[A-Z]\d{6}$/';
        return preg_match($pattern, $trackingNumber) === 1;
    }

    /**
     * Parse a tracking number into its components
     *
     * @param string $trackingNumber
     * @return array|null
     */
    public static function parse(string $trackingNumber): ?array
    {
        if (!self::validate($trackingNumber)) {
            return null;
        }

        $parts = explode('-', $trackingNumber);
        
        return [
            'carrier' => $parts[0],
            'date' => self::parseDate($parts[1]),
            'location_code' => $parts[2],
            'location_name' => self::LOCATIONS[$parts[2]] ?? 'Unknown',
            'package_type' => $parts[3][0],
            'package_type_name' => self::$typeLabels[$parts[3][0]] ?? 'Unknown',
            'sequence' => substr($parts[3], 1),
        ];
    }

    /**
     * Parse date from DDMMYY format
     *
     * @param string $dateStr
     * @return Carbon
     */
    protected static function parseDate(string $dateStr): Carbon
    {
        $day = substr($dateStr, 0, 2);
        $month = substr($dateStr, 2, 2);
        $year = substr($dateStr, 4, 2);
        
        // Handle 2-digit year (assume 20XX for years 00-99)
        $fullYear = '20' . $year;
        
        return Carbon::createFromFormat('Y-m-d', "$fullYear-$month-$day");
    }

    /**
     * Get tracking URL for QR code
     *
     * @param string $trackingNumber
     * @param string $baseUrl
     * @return string
     */
    public static function getTrackingUrl(string $trackingNumber, string $baseUrl = 'https://track.tr3slog.com'): string
    {
        return "$baseUrl/$trackingNumber";
    }

    /**
     * Get all available location codes
     *
     * @return array
     */
    public static function getLocations(): array
    {
        return self::LOCATIONS;
    }

    /**
     * Get all available package types
     *
     * @return array
     */
    public static function getPackageTypes(): array
    {
        return self::TYPE_LABELS;
    }
}
