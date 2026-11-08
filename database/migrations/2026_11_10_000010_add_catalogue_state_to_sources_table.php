<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M14 — `catalogue_state` : l'état de publication devient distinct de la
 * (disparue) notion de source privée. 'active' = proposée dans le
 * catalogue ; 'withdrawn' = retirée du catalogue actif mais toujours
 * commune — réactivation par revalidation explicite uniquement.
 * `is_catalogue` est conservée (toutes communes) ; `orphaned_at` dormante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table): void {
            $table->string('catalogue_state', 20)->default('active')
                ->after('is_catalogue')->index();
        });
        // Sources retirées M13.3b : is_catalogue=0 + catalogue_inactive_at
        // renseigné → catalogue_state='withdrawn' (état restauré).
        DB::table('sources')->where('is_catalogue', false)
            ->whereNotNull('catalogue_inactive_at')
            ->update(['catalogue_state' => 'withdrawn']);
        // Toutes les sources sont communes.
        DB::table('sources')->where('is_catalogue', false)->update(['is_catalogue' => true]);
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table): void {
            $table->dropIndex(['catalogue_state']);
            $table->dropColumn('catalogue_state');
        });
    }
};
