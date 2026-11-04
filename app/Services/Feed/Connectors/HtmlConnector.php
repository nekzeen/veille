<?php

declare(strict_types=1);

namespace App\Services\Feed\Connectors;

use App\Models\Source;
use App\Services\Feed\Data\FeedItemData;
use App\Services\Feed\Data\ParsedFeed;
use App\Services\Feed\Exceptions\FeedFetchException;
use App\Services\Feed\FeedClientInterface;
use App\Services\Feed\Html\HtmlExtractor;

/**
 * Connecteur de pages HTML (M8.4) : extraction par `HtmlExtractor`
 * (JSON-LD → Open Graph → structures répétitives → sélecteurs).
 *
 * Enrichissement facultatif et borné par `config.enrich_pages`
 * (0-3, défaut 0) : les N premiers articles sans date ni résumé
 * déclenchent au plus une récupération chacun — même pipeline
 * sécurisé — pour compléter les champs manquants (jamais écraser un
 * champ présent). La collecte réussit même si l'enrichissement échoue.
 */
final class HtmlConnector implements ConnectorInterface
{
    public function __construct(
        private readonly FeedClientInterface $client,
        private readonly HtmlExtractor $extractor,
    ) {}

    public function collect(Source $source): ConnectorResult
    {
        $result = $this->client->fetch(
            $source->fetch_url ?? $source->feed_url,
            $source->etag,
            $source->last_modified,
        );

        $parsed = null;
        if (! $result->notModified) {
            $parsed = $this->extractor->extract($result->body, $result->effectiveUrl, $source->config);
            $enriched = $this->enrich($source, $parsed->items);
            if ($enriched !== $parsed->items) {
                $parsed = new ParsedFeed(
                    $parsed->format, $parsed->title, $parsed->siteUrl,
                    $parsed->description, $parsed->language, $enriched, $parsed->warnings,
                );
            }
        }

        return new ConnectorResult(
            parsed: $parsed,
            statusCode: $result->statusCode,
            etag: $result->etag,
            lastModified: $result->lastModified,
        );
    }

    /**
     * @param  list<FeedItemData>  $items
     * @return list<FeedItemData>
     */
    private function enrich(Source $source, array $items): array
    {
        $config = $source->config ?? [];
        $limit = min(HtmlExtractor::MAX_ENRICH_PAGES, max(0, (int) ($config['enrich_pages'] ?? 0)));

        foreach ($items as $index => $item) {
            if ($limit === 0) {
                break;
            }
            if ($item->url === null || ($item->summary !== null && $item->publishedAt !== null)) {
                continue;
            }
            $limit--;

            try {
                $page = $this->client->fetch($item->url);
            } catch (FeedFetchException) {
                continue; // échec d'enrichissement : article conservé tel quel
            }

            $meta = $this->extractor->extractArticleMetadata($page->body, $page->effectiveUrl);
            $items[$index] = new FeedItemData(
                guid: $item->guid,
                guidIsPermalink: $item->guidIsPermalink,
                url: $item->url,
                title: $item->title ?? $meta['title'],
                author: $item->author ?? $meta['author'],
                summary: $item->summary ?? $meta['summary'],
                content: $item->content,
                publishedAt: $item->publishedAt ?? $meta['published_at'],
                updatedAt: $item->updatedAt,
            );
        }

        return $items;
    }
}
