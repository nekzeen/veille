<?php

namespace Database\Factories;

use App\Models\Article;
use App\Models\ArticleUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ArticleUser> */
class ArticleUserFactory extends Factory
{
    protected $model = ArticleUser::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'article_id' => Article::factory(),
            'is_read' => false,
            'is_favorite' => false,
            'is_archived' => false,
        ];
    }
}
