<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M12.4 — Traçabilité de l'acceptation des CGU et de la version de la
 * politique de confidentialité présentée à l'inscription.
 *
 * Toutes les colonnes sont NULLABLE : les comptes existants n'ont
 * jamais eu à accepter → aucun consentement artificiel n'est créé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('terms_version', 20)->nullable()->after('remember_token');
            $table->timestamp('terms_accepted_at')->nullable()->after('terms_version');
            $table->string('privacy_version', 20)->nullable()->after('terms_accepted_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['terms_version', 'terms_accepted_at', 'privacy_version']);
        });
    }
};
