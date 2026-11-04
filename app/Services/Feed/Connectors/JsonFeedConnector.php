<?php

declare(strict_types=1);

namespace App\Services\Feed\Connectors;

use App\Models\Source;
use App\Services\Feed\FeedClientInterface;
use App\Services\Feed\JsonFeedParser;

/**
 * Connecteur JSON Feed (M8.4) — même contrat que `RssConnector` :
 * récupération sécurisée conditionnelle puis parsing en
 * `FeedItemData[]` normalisés, consommés par le pipeline existant.
 */
final class JsonFeedConnector implements ConnectorInterface
{
    public function __construct(
        private readonly FeedClientInterface $client,
        private readonly JsonFeedParser $parser,
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
