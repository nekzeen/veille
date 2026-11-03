<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Identifiant technique monotone d'insertion, watermark des
     * digests (DataModel §3.11) : la PK composite (veille_id,
     * article_id) est conservée ; l'auto-incrément exige seulement
     * d'ouvrir un index (ici UNIQUE) sous InnoDB/MariaDB 10.11.
     * SQLite ne supportant pas AUTOINCREMENT hors PK, la colonne y
     * est plainte et valorisée par le modèle (ArticleVeille::creating).
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table('article_veille', function (Blueprint $table) {
                $table->unsignedBigInteger('id')->nullable()->unique();
            });
        } else {
            DB::statement('ALTER TABLE article_veille ADD COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE FIRST');
        }
    }

    public function down(): void
    {
        Schema::table('article_veille', function (Blueprint $table) {
            $table->dropUnique(['id']);
            $table->dropColumn('id');
        });
    }
};
