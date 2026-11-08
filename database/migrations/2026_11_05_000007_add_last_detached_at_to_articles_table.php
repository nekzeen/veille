<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M12.11 — Date du dernier détachement d'un article des veilles.
 * NULL = jamais détaché observé ; utilisé pour la grâce de 7 jours
 * avant purge du catalogue orphelin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table): void {
            $table->timestamp('last_detached_at')->nullable()->index()
                ->after('collected_at');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table): void {
            $table->dropIndex(['last_detached_at']);
            $table->dropColumn('last_detached_at');
        });
    }
};
