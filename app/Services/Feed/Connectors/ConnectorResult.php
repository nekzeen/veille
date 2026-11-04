<?php

declare(strict_types=1);

namespace App\Services\Feed\Connectors;

use App\Services\Feed\Data\ParsedFeed;

/**
 * Résultat normalisé d'une collecte (M8.2) : contenu parsé + métadonnées
 * de la réponse nécessaires à `FetchFeedJob` (validateurs HTTP,
 * code statut pour les journaux).
 */
final class ConnectorResult
{
    public function __construct(
        public readonly ?ParsedFeed $parsed,
        public readonly int $statusCode,
        public readonly ?string $etag,
        public readonly ?string $lastModified,
    ) {}
}
