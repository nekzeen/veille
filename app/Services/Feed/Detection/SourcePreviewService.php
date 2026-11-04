<?php

declare(strict_types=1);

namespace App\Services\Feed\Detection;

use App\Services\Feed\Data\FeedItemData;

/**
 * Prévisualisation (M8.3) : détection + au plus 5 articles normalisés
 * (`FeedItemData`, format commun M8.2). Lecture seule : aucune source,
 * aucun article, aucun rapprochement de mots-clés. Un format sans
 * connecteur disponible renvoie `connector_unavailable`, jamais de
 * résultats fabriqués.
 */
final class SourcePreviewService
{
    public const MAX_ITEMS = 5;

    private const SUMMARY_MAX = 300;

    public function __construct(private readonly SourceDetector $detector) {}

    /**
     * @param  array<string, mixed>|null  $config
     * @return array<string, mixed>
     */
    public function preview(string $url, ?array $config = null): array
    {
        $detection = $this->detector->detect($url, $config);

        $preview = match (true) {
            ! $detection->isSuccessful() => ['status' => 'unavailable', 'items' => []],
            ! $detection->connectorAvailable || $detection->parsed === null => [
                'status' => 'connector_unavailable',
                'message' => 'Prévisualisation disponible avec le connecteur de ce format (évolution prévue).',
                'items' => [],
            ],
            default => [
                'status' => 'ok',
                'items' => array_map(
                    self::item(...),
                    array_slice($detection->parsed->items, 0, self::MAX_ITEMS),
                ),
            ],
        };

        return [...$detection->toArray(), 'preview' => $preview];
    }

    /** @return array{title: ?string, url: ?string, summary: ?string, published_at: ?string, missing: list<string>} */
    private static function item(FeedItemData $item): array
    {
        $data = [
            'title' => self::text($item->title, 200),
            'url' => $item->url !== null && preg_match('#^https?://#i', $item->url) === 1 ? $item->url : null,
            'summary' => self::text($item->summary ?? $item->content, self::SUMMARY_MAX),
            'published_at' => $item->publishedAt?->toIso8601ZuluString(),
        ];

        return [...$data, 'missing' => array_keys(array_filter($data, static fn (?string $v): bool => $v === null))];
    }

    private static function text(?string $html, int $max): ?string
    {
        if ($html === null) {
            return null;
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return $text === '' ? null : mb_strimwidth($text, 0, $max, '…');
    }
}
