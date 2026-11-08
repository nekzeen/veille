<?php

declare(strict_types=1);

namespace App\Console\Concerns;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Garde-fou d'écriture (règle M13.1d) : --execute n'écrit librement que
 * sur une base de test réellement connectée (`:memory:`/`veille_test`,
 * jamais APP_ENV). Ailleurs : --force + confirmation interactive par
 * saisie du NOM EXACT de la base. Non interactif / saisie fausse /
 * interrompue / nom indéterminé = refus.
 *
 * @mixin Command
 */
trait GuardsDatabaseWrites
{
    /** Vrai si l'écriture est autorisée ; sinon message d'erreur émis. */
    protected function writeAllowed(): bool
    {
        $db = (string) DB::connection()->getDatabaseName();
        if ($db === '') {
            $this->error('--execute refusé : nom de base indéterminé — configuration ambiguë.');

            return false;
        }
        if ($db === ':memory:' || $db === 'veille_test') {
            return true;
        }
        if (! $this->option('force')) {
            $this->error("--execute refusé : base « $db » hors environnement de test — ajouter --force.");

            return false;
        }
        if (! $this->input->isInteractive()) {
            $this->error('--force insuffisant : exécution non interactive — confirmation impossible.');

            return false;
        }
        try {
            $typed = $this->ask("Écriture demandée sur la base « $db » — saisissez son nom exact pour confirmer");
        } catch (\Throwable) {
            $typed = null;
        }
        if ($typed !== $db) {
            $this->error('Confirmation échouée — aucune donnée modifiée.');

            return false;
        }

        return true;
    }
}
