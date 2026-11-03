<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Curseurs mécaniques du digest (DataModel §3.11) : positions sur
     * article_veille.id, sans FK — ce sont des bornes de fenêtre, pas
     * des références vivantes (la correspondance peut être supprimée
     * ou purgée). start_match_id = end_match_id du dernier digest
     * envoyé ; end_match_id = dernier élément réellement sélectionné.
     */
    public function up(): void
    {
        Schema::table('veille_alerts', function (Blueprint $table) {
            $table->unsignedBigInteger('start_match_id')->nullable()->after('period_start');
            $table->unsignedBigInteger('end_match_id')->nullable()->after('period_end');
        });
    }

    public function down(): void
    {
        Schema::table('veille_alerts', function (Blueprint $table) {
            $table->dropColumn(['start_match_id', 'end_match_id']);
        });
    }
};
