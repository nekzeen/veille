<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Veille;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Veille> */
class VeilleFactory extends Factory
{
    protected $model = Veille::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->unique()->words(3, true),
            'description' => $this->faker->sentence(),
            'is_active' => true,
            'alerts_enabled' => false,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function withAlerts(): static
    {
        return $this->state(fn () => ['alerts_enabled' => true]);
    }
}
