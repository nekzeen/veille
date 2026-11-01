<?php

namespace App\Services\Feed\Data;

use Carbon\CarbonImmutable;

/**
 * Article normalisé issu du parsing d'un flux — DTO sans logique.
 *
 * Champ absent dans le flux → `null` (jamais de valeur inventée).
 * Les dates sont des instantanés UTC (timestamp epoch converti en
 * CarbonImmutable) ; une date absente ou illisible → `null`, le cas
 * illisible étant également signalé dans les warnings du flux.
 *
 * `guidIsPermalink` indique si l'identifiant déclaré est exploitable
 * comme clé primaire de déduplication (DataModel §4.B.3) :
 * - RSS `<guid isPermaLink>` : true | false | absent (= true, défaut spec) ;
 * - Atom `<id>` : toujours true (identifiant permanent, RFC 4287) ;
 * - autre identifiant déclaré (dc:identifier, rdf:about) : true ;
 * - aucun identifiant déclaré : `guid` = null et ce drapeau = false.
 */
final class FeedItemData
{
    public function __construct(
        public readonly ?string $guid,
        public readonly bool $guidIsPermalink,
        public readonly ?string $url,
        public readonly ?string $title,
        public readonly ?string $author,
        public readonly ?string $summary,
        public readonly ?string $content,
        public readonly ?CarbonImmutable $publishedAt,
        public readonly ?CarbonImmutable $updatedAt,
    ) {}
}
