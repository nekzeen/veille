<?php

namespace Database\Factories;

use App\Models\Veille;
use App\Models\VeilleKeyword;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VeilleKeyword> */
class VeilleKeywordFactory extends Factory
{
    protected $model = VeilleKeyword::class;

    public function definition(): array
    {
        return [
            'veille_id' => Veille::factory(),
            'term' => $this->faker->unique()->word(),
        ];
    }
}
