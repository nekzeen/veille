<?php

namespace App\Models;

use Database\Factories\SourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Source extends Model
{
    /** @use HasFactory<SourceFactory> */
    use HasFactory;

    protected $fillable = [
        'feed_url',
        'name',
        'site_url',
        'description',
        'is_catalogue',
        'is_active',
        'orphaned_at',
    ];

    protected function casts(): array
    {
        return [
            'is_catalogue' => 'boolean',
            'is_active' => 'boolean',
            'orphaned_at' => 'datetime',
            'last_fetched_at' => 'datetime',
            'last_success_at' => 'datetime',
        ];
    }

    /** @return HasMany<UserSource, $this> */
    public function userSources(): HasMany
    {
        return $this->hasMany(UserSource::class);
    }

    /** @return HasMany<Article, $this> */
    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }
}
