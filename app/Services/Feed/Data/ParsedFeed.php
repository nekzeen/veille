<?php

namespace App\Services\Feed\Data;

use App\Enums\FeedFormat;

/**
 * Résultat normalisé du parsing d'un document de flux — DTO sans logique.
 *
 * `items` est la liste ordonnée des articles du flux (éventuellement
 * vide : un flux valide sans article n'est pas une erreur).
 * `warnings` contient les anomalies non bloquantes par item
 * (date illisible, article sans URL/titre…) ; le contenu des flux
 * n'y est jamais recopié.
 */
final class ParsedFeed
{
    /**
     * @param  list<FeedItemData>  $items
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly FeedFormat $format,
        public readonly ?string $title,
        public readonly ?string $siteUrl,
        public readonly ?string $description,
        public readonly ?string $language,
        public readonly array $items,
        public readonly array $warnings = [],
    ) {}
}
