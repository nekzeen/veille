<?php

/**
 * M13.3b — exécution planifiée de `catalogue:reconcile-activity`
 * (marquage d'inactivité + retrait après 90 jours).
 *
 * DÉSACTIVÉE par défaut. L'exécution planifiée n'utilise jamais
 * --force : elle n'écrit que si les DEUX conditions sont réunies :
 *   1. CATALOGUE_SCHEDULED_EXECUTE=true ;
 *   2. CATALOGUE_SCHEDULED_DATABASE = nom EXACT de la base réellement
 *      connectée (DB::connection()->getDatabaseName()).
 * Toute autre situation (valeur absente, vide, base différente) :
 * refus, aucune écriture. Activer uniquement après application de la
 * migration 000009 et validation (voir Production_Runbook.md).
 */
return [
    'scheduled' => [
        'enabled' => (bool) env('CATALOGUE_SCHEDULED_EXECUTE', false),
        'database' => env('CATALOGUE_SCHEDULED_DATABASE'),
    ],
];
