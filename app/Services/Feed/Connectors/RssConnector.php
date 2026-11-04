<?php

declare(strict_types=1);

namespace App\Services\Feed\Connectors;

use App\Models\Source;
use App\Services\Feed\FeedClientInterface;
use App\Services\Feed\FeedParserInterface;

/**
 * Connecteur RSS/Atom — enveloppe du chemin de collecte existant
 * (`SecureFeedClient` + `SimplePieFeedParser`). Comportement identique
 * à la collecte historique : requête conditionnelle ETag/Last-Modified
 * sur `fetch_url` (repli `feed_url` pour les sources pré-M8).
 */
final class RssConnector implements ConnectorInterface
{
    public function __construct(
        private readonly FeedClientInterface $client,
        private readonly FeedParserInterface $parser,
    ) {}

    public function collect(Source $source): ConnectorResult
    {
        $result = $this->client->fetch(
            $source->fetch_url ?? $source->feed_url,
            $source->etag,
            $source->last_modified,
        );

        return new ConnectorResult(
            parsed: $result->notModified ? null : $this->parser->parse($result->body),
            statusCode: $result->statusCode,
            etag: $result->etag,
            lastModified: $result->lastModified,
        );
    }
}
