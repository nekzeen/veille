<?php

namespace Database\Factories;

use App\Models\Article;
use App\Models\ArticleVeille;
use App\Models\Veille;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ArticleVeille> */
class ArticleVeilleFactory extends Factory
{
    protected $model = ArticleVeille::class;

    public function definition(): array
    {
        return [
            'veille_id' => Veille::factory(),
            'article_id' => Article::factory(),
            'matched_keyword' => $this->faker->word(),
            'created_at' => now('UTC'),
        ];
    }
}
