<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M13.2a — référentiel de thématiques du catalogue commun.
 * `sources.theme_id` NULLABLE : les sources privées historiques non
 * classées restent valides ; l'exigence de thématique s'appliquera à
 * la publication (M13.2b), pas rétroactivement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_themes', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->timestamps();
        });

        Schema::table('sources', function (Blueprint $table): void {
            $table->foreignId('theme_id')->nullable()->after('description')
                ->constrained('source_themes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('theme_id');
        });
        Schema::dropIfExists('source_themes');
    }
};
