<?php

namespace App\Models;

use Database\Factories\UserSourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSource extends Model
{
    /** @use HasFactory<UserSourceFactory> */
    use HasFactory;

    protected $table = 'user_sources';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'source_id',
        'custom_name',
        'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Source, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}
