<?php

/**
 * Versions des documents juridiques présentés à l'inscription (M12.4).
 *
 * ⚠ VALEURS PROVISOIRES en attente des documents définitifs : le
 * préfixe 'draft-' signale explicitement qu'aucun texte juridique
 * n'est encore validé. À remplacer par les versions réelles
 * (ex. '2026-11') lors de la publication des /terms et /privacy.
 * Incrémenter la version à chaque modification du document — les
 * utilisateurs ayant accepté une version plus ancienne le verront.
 */
return [
    'terms_version' => env('LEGAL_TERMS_VERSION', 'draft-0'),
    'privacy_version' => env('LEGAL_PRIVACY_VERSION', 'draft-0'),

    /*
    | M12.15.1 — activation EXPLICITE du consentement obligatoire.
    | Tant que 'enforce' est faux (défaut, ou LEGAL_ENFORCE absent) :
    | la case n'est ni affichée ni exigée et RIEN n'est enregistré.
    | Activer UNIQUEMENT quand les documents sont validés ET la
    | migration 000006 appliquée : LEGAL_ENFORCE=true + versions
    | définitives (ex. '2026-11'). Indépendant du statut draft.
    */
    'enforce' => env('LEGAL_ENFORCE', false),
];
