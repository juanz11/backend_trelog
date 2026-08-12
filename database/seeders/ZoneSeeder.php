<?php

namespace Database\Seeders;

use App\Models\Zone;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ZoneSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $zones = [
            [
                'code' => 'SDQ',
                'name' => 'Santo Domingo',
                'description' => 'Zona metropolitana de Santo Domingo',
                'is_active' => true,
                'delivery_types' => 'same-day,standard,express',
            ],
            [
                'code' => 'SDE',
                'name' => 'Santiago',
                'description' => 'Zona metropolitana de Santiago',
                'is_active' => true,
                'delivery_types' => 'same-day,standard,express',
            ],
            [
                'code' => 'LAR',
                'name' => 'La Romana',
                'description' => 'Zona de La Romana y alrededores',
                'is_active' => true,
                'delivery_types' => 'standard,express',
            ],
            [
                'code' => 'SJB',
                'name' => 'San Juan de la Maguana',
                'description' => 'Zona de San Juan de la Maguana',
                'is_active' => true,
                'delivery_types' => 'standard',
            ],
        ];

        foreach ($zones as $zone) {
            Zone::firstOrCreate(
                ['code' => $zone['code']],
                [
                    'name' => $zone['name'],
                    'description' => $zone['description'],
                    'is_active' => $zone['is_active'],
                    'delivery_types' => $zone['delivery_types'],
                ]
            );
        }

        $this->command->info('Zones seeded successfully.');
    }
}
