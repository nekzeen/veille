<?php

declare(strict_types=1);

namespace App\Services\Feed;

use App\Enums\FeedFormat;
use App\Services\Feed\Data\FeedItemData;
use App\Services\Feed\Data\ParsedFeed;
use App\Services\Feed\Exceptions\FeedParseException;
use Carbon\CarbonImmutable;

/**
 * Transforme un document JSON Feed (versions 1 et 1.1,
 * `https://jsonfeed.org/spec`) en `ParsedFeed` normalisé (M8.4).
 * Aucune E/S : le document est fourni en mémoire. Champs absents →
 * `null` jamais inventés ; anomalies non bloquantes → `warnings`.
 */
final class JsonFeedParser
{
    /** Versions acceptées : 1 et 1.1 (barre finale optionnelle). */
    private const ACCEPTED_VERSIONS = '#^https?://jsonfeed\.org/version/1(\.1)?/?$#';

    /**
     * @throws FeedParseException JSON illisible, pas un JSON Feed ou version non prise en charge
     */
    public function parse(string $body): ParsedFeed
    {
        if (trim($body) === '') {
            throw new FeedParseException('Document JSON vide.');
        }

        $feed = json_decode($body, true, 512);
        if (! is_array($feed)) {
            throw new FeedParseException('Document JSON illisible : '.json_last_error_msg().'.');
        }

        $version = $feed['version'] ?? null;
        if (! is_string($version)) {
            throw new FeedParseException('Le document JSON n\'est pas un JSON Feed (champ « version » absent).');
        }
        if (preg_match(self::ACCEPTED_VERSIONS, $version) !== 1) {
            throw new FeedParseException("Version JSON Feed non prise en charge : {$version}.");
        }

        if (isset($feed['items']) && ! is_array($feed['items'])) {
            throw new FeedParseException('Champ « items » invalide : une liste d\'articles est attendue.');
        }

        $items = [];
        $warnings = [];
        foreach (array_values($feed['items'] ?? []) as $position => $raw) {
            if (is_array($raw)) {
                $items[] = $this->mapItem($raw, $position, $warnings);
            }
        }

        return new ParsedFeed(
            format: FeedFormat::JsonFeed,
            title: self::str($feed['title'] ?? null),
            siteUrl: self::url($feed['home_page_url'] ?? null),
            description: self::str($feed['description'] ?? null),
            language: self::str($feed['language'] ?? null),
            items: $items,
            warnings: $warnings,
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  list<string>  $warnings
     */
    private function mapItem(array $raw, int $position, array &$warnings): FeedItemData
    {
        // `id` est requis par la spec : unique dans le flux → clé de
        // déduplication (même rôle que `<id>` Atom).
        $id = self::str($raw['id'] ?? null);
        if ($id === null) {
            $warnings[] = "item {$position} : « id » absent — déduplication sur l'URL.";
        }

        $author = self::str($raw['author']['name'] ?? $raw['authors'][0]['name'] ?? null);

        // `summary` explicite seulement — jamais de troncation inventée.
        $summary = self::str($raw['summary'] ?? null);
        $content = self::str($raw['content_html'] ?? $raw['content_text'] ?? null);

        return new FeedItemData(
            guid: $id,
            guidIsPermalink: $id !== null,
            url: self::url($raw['url'] ?? $raw['external_url'] ?? null),
            title: self::str($raw['title'] ?? null),
            author: $author,
            summary: $summary,
            content: $content,
            publishedAt: self::date($raw['date_published'] ?? null, 'date_published', $position, $warnings),
            updatedAt: self::date($raw['date_modified'] ?? null, 'date_modified', $position, $warnings),
        );
    }

    private static function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** URL http(s) absolue uniquement — rien d'autre n'est consommé. */
    private static function url(mixed $value): ?string
    {
        $value = self::str($value);

        return $value !== null && preg_match('#^https?://#i', $value) === 1 ? $value : null;
    }

    /** @param  list<string>  $warnings */
    private static function date(mixed $value, string $field, int $position, array &$warnings): ?CarbonImmutable
    {
        $value = self::str($value);
        if ($value === null) {
            return null;
        }
        try {
            return (new CarbonImmutable($value))->utc();
        } catch (\Throwable) {
            $warnings[] = "item {$position} : « {$field} » illisible (« {$value} »).";

            return null;
        }
    }
}
