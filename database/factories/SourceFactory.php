<?php

namespace Database\Factories;

use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Source> */
class SourceFactory extends Factory
{
    protected $model = Source::class;

    public function definition(): array
    {
        return [
            'feed_url' => 'https://'.$this->faker->unique()->domainName().'/feed',
            'name' => $this->faker->words(3, true),
            'site_url' => 'https://'.$this->faker->domainName(),
            'description' => $this->faker->sentence(),
            'is_catalogue' => false,
            'is_active' => true,
        ];
    }

    public function catalogue(): static
    {
        return $this->state(fn () => ['is_catalogue' => true, 'catalogue_state' => 'active']);
    }
}
