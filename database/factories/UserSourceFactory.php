<?php

namespace Database\Factories;

use App\Models\Source;
use App\Models\User;
use App\Models\UserSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UserSource> */
class UserSourceFactory extends Factory
{
    protected $model = UserSource::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'source_id' => Source::factory(),
            'created_at' => now(),
        ];
    }
}
