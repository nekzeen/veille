<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Veille;
use App\Models\VeilleAlert;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VeilleAlert> */
class VeilleAlertFactory extends Factory
{
    protected $model = VeilleAlert::class;

    public function definition(): array
    {
        return [
            'veille_id' => Veille::factory(),
            'user_id' => User::factory(),
            'digest_date' => now('Europe/Paris')->toDateString(),
            'status' => VeilleAlert::STATUS_PENDING,
            'period_start' => now('UTC')->subDay(),
            'start_match_id' => 0,
            'period_end' => now('UTC'),
            'end_match_id' => 0,
        ];
    }
}
