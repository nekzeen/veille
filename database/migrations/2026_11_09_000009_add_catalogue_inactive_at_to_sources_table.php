<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M13.3a — début d'inactivité d'une source du catalogue commun (aucun
 * lien `user_sources` ni `source_veille`). Distinct de `orphaned_at`
 * (sources privées, M13.1) : les deux marqueurs ne se substituent
 * jamais l'un à l'autre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table): void {
            $table->timestamp('catalogue_inactive_at')->nullable()->after('orphaned_at');
            $table->index('catalogue_inactive_at');
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table): void {
            $table->dropIndex(['catalogue_inactive_at']);
            $table->dropColumn('catalogue_inactive_at');
        });
    }
};
