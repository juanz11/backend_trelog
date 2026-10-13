<?php

namespace Database\Factories;

use App\Models\DeliveryRoute;
use App\Models\RouteStop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RouteStop>
 */
class RouteStopFactory extends Factory
{
    protected $model = RouteStop::class;

    public function definition(): array
    {
        return [
            'route_id' => DeliveryRoute::factory(),
            'n' => 1,
            'name' => $this->faker->company(),
            'addr' => $this->faker->address(),
            'type' => 'delivery',
            'eta' => '10:30',
            'state' => 'pending',
        ];
    }
}
