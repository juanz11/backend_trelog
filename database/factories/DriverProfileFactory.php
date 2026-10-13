<?php

namespace Database\Factories;

use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DriverProfile>
 */
class DriverProfileFactory extends Factory
{
    protected $model = DriverProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'initials' => strtoupper($this->faker->lexify('??')),
            'vehicle' => $this->faker->randomElement(['Van 12', 'Camion 04', 'Moto 7']),
            'hub' => $this->faker->randomElement(['Santo Domingo', 'Santiago', 'La Romana']),
            'available' => true,
        ];
    }
}
