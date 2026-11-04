<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M9.3 — justifications de masquage (M9_Conception_Exclusions §9.1).
 *
 * Écart de conception assumé : la PK (user_id, article_id, exclusion_id)
 * est impossible car InnoDB interdit NULL dans une clé primaire, or
 * `exclusion_id` doit rester NULL pour le masquage manuel futur.
 * Équivalent : `id` auto-incrémenté + index UNIQUE sur le triplet —
 * l'unicité des justifications (exclusion_id non NULL) est garantie par
 * l'index ; les lignes manuelles (NULL) seront gérées en code si
 * nécessaire (M9.4+).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_user_hidden', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->foreignId('exclusion_id')->nullable()
                ->constrained('user_exclusions')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['user_id', 'article_id', 'exclusion_id'], 'auh_uniq');
            $table->index('exclusion_id', 'auh_exclusion');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_user_hidden');
    }
};
