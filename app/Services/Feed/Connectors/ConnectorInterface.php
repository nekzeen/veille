<?php

declare(strict_types=1);

namespace App\Services\Feed\Connectors;

use App\Models\Source;
use App\Services\Feed\Exceptions\FeedFetchException;
use App\Services\Feed\Exceptions\FeedParseException;

/**
 * Connecteur de collecte (M8.2) : récupère et normalise les articles
 * d'une source. `ConnectorResult::parsed` (`FeedItemData[]`) est le
 * format normalisé commun consommé par `ArticleUpsertService` et
 * `KeywordMatcher` — `null` = aucun changement (304).
 */
interface ConnectorInterface
{
    /**
     * @throws FeedFetchException échec de récupération
     * @throws FeedParseException contenu inexploitable
     */
    public function collect(Source $source): ConnectorResult;
}
