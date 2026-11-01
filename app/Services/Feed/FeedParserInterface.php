<?php

namespace App\Services\Feed;

use App\Services\Feed\Data\ParsedFeed;
use App\Services\Feed\Exceptions\FeedParseException;

/**
 * Transforme un document XML RSS/Atom brut en représentation
 * normalisée. Aucune E/S : le XML est fourni en mémoire par l'appelant
 * (le transport HTTP relève exclusivement du client sécurisé futur).
 */
interface FeedParserInterface
{
    /**
     * @throws FeedParseException si le document n'est pas un flux
     *                            exploitable (vide, XML illisible, non RSS/Atom).
     */
    public function parse(string $xml): ParsedFeed;
}
