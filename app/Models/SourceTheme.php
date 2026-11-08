<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** M13.2a — thématique du catalogue commun (une par source). */
class SourceTheme extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug'];

    /** @return HasMany<Source, $this> */
    public function sources(): HasMany
    {
        return $this->hasMany(Source::class, 'theme_id');
    }
}
