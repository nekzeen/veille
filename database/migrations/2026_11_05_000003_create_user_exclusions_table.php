<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M9.1 — règles d'exclusion personnelles (M9_Conception_Exclusions §2).
 * Personnelles (user_id), communes à toutes les veilles de l'utilisateur ;
 * `article_user_hidden` arrive en M9.3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_exclusions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10); // 'url' | 'keyword'
            $table->string('pattern', 500);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            // 3×500+ utf8mb4 tient sous la limite InnoDB de 3072 octets.
            $table->unique(['user_id', 'kind', 'pattern']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_exclusions');
    }
};
