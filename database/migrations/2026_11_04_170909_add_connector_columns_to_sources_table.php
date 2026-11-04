<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M8.2 — socle multiconnecteur : colonnes de configuration des sources.
 * Additif : backfill rss/validated, feed_url dupliqué sur les deux
 * champs d'URL. `down()` réversible sans perte (colonnes nouvelles).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table): void {
            $table->string('submitted_url', 1024)->nullable()->after('feed_url');
            $table->string('fetch_url', 1024)->nullable()->after('submitted_url');
            $table->string('connector', 16)->default('rss')->after('fetch_url');
            $table->json('config')->nullable()->after('connector');
            $table->string('config_state', 12)->default('detected')->after('config');
            $table->json('detection')->nullable()->after('config_state');
        });

        DB::table('sources')->update([
            'submitted_url' => DB::raw('feed_url'),
            'fetch_url' => DB::raw('feed_url'),
            'connector' => 'rss',
            'config_state' => 'validated',
        ]);
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table): void {
            $table->dropColumn([
                'submitted_url',
                'fetch_url',
                'connector',
                'config',
                'config_state',
                'detection',
            ]);
        });
    }
};
