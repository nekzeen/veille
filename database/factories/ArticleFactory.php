<?php

namespace Database\Factories;

use App\Models\Article;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Article> */
class ArticleFactory extends Factory
{
    protected $model = Article::class;

    public function definition(): array
    {
        return [
            'source_id' => Source::factory(),
            'guid' => $this->faker->uuid(),
            'dedup_key' => hash('sha256', $this->faker->uuid()),
            'url' => 'https://'.$this->faker->domainName().'/'.$this->faker->slug(),
            'url_hash' => hash('sha256', $this->faker->uuid()),
            'content_hash' => hash('sha256', $this->faker->uuid()),
            'title' => $this->faker->sentence(),
            'author' => $this->faker->name(),
            'summary' => $this->faker->sentence(),
            'content' => '<p>'.$this->faker->paragraph().'</p>',
            'published_at' => now('UTC'),
            'collected_at' => now('UTC'),
        ];
    }
}
