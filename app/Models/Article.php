<?php

namespace App\Models;

use Database\Factories\ArticleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Article extends Model
{
    /** @use HasFactory<ArticleFactory> */
    use HasFactory;

    protected $fillable = [
        'source_id',
        'guid',
        'dedup_key',
        'url',
        'url_hash',
        'content_hash',
        'title',
        'author',
        'summary',
        'content',
        'published_at',
        'collected_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'collected_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Source, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}
