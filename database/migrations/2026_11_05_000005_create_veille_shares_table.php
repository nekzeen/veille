<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M10.2 — liens de partage en lecture seule (M10_Conception_Partage §2).
 *
 * `token_hash` = SHA-256 seul — le jeton clair n'est jamais stocké.
 * `rotated_at` : ajout par rapport à §2 pour conserver l'audit de la
 * dernière rotation (distinct de `revoked_at`, réservé à la révocation
 * définitive) — évolution minimale actée en M10.2.
 *
 * Cohérence scope/veille_id : CHECK natif sur MariaDB (10.11 applique
 * les CHECK) via statement post-création ; SQLite ne permet pas
 * l'ajout d'une CHECK par ALTER TABLE — l'invariant est alors garanti
 * par la validation applicative (tests des deux côtés).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('veille_shares', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('veille_id')->nullable()
                ->constrained('veilles')->cascadeOnDelete();
            $table->string('scope', 8); // 'single' | 'all'
            $table->char('token_hash', 64)->unique();
            $table->string('label', 120)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('rotated_at')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement(
                "ALTER TABLE veille_shares ADD CONSTRAINT chk_scope_veille
                 CHECK ((scope = 'single' AND veille_id IS NOT NULL)
                    OR (scope = 'all' AND veille_id IS NULL))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('veille_shares');
    }
};
