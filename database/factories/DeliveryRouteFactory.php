<?php

namespace Database\Factories;

use App\Models\DeliveryRoute;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryRoute>
 *
 * El modelo se llama DeliveryRoute y la tabla `routes`: Laravel deriva el nombre de
 * la factory de la CLASE, no de la tabla, asi que este archivo tiene que llamarse
 * DeliveryRouteFactory y no RouteFactory.
 */
class DeliveryRouteFactory extends Factory
{
    protected $model = DeliveryRoute::class;

    public function definition(): array
    {
        return [
            'driver_id' => User::factory(),
            'code' => strtoupper($this->faker->bothify('R-####')),
            'date_label' => now()->toDateString(),
            'stops_count' => 0,
            'duration' => '2h 30m',
            'vehicle' => 'Van 12',
            'status' => 'pending',
            'progress' => 0.0,
            'instructions' => $this->faker->sentence(),
        ];
    }

    /** Una ruta que pertenece a OTRA persona. Es el caso que prueba el aislamiento. */
    public function deOtroConductor(): static
    {
        return $this->state(fn () => ['driver_id' => User::factory()]);
    }
}
