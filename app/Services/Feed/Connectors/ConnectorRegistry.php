<?php

declare(strict_types=1);

namespace App\Services\Feed\Connectors;

use App\Models\Source;
use App\Services\Feed\Exceptions\FeedParseException;
use Illuminate\Contracts\Container\Container;

/**
 * Sélection du connecteur selon `sources.connector` (M8.2).
 * `rss` est la valeur par défaut — les sources existantes et celles
 * créées avant M8.5 utilisent `RssConnector` sans rien d'autre.
 */
final class ConnectorRegistry
{
    /** @var array<string, class-string<ConnectorInterface>> */
    private const CONNECTORS = [
        'rss' => RssConnector::class,
        'atom' => RssConnector::class,
        'json_feed' => JsonFeedConnector::class,
        'html' => HtmlConnector::class,
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * @throws FeedParseException connecteur non supporté
     */
    public function for(Source $source): ConnectorInterface
    {
        $type = $source->connector ?? 'rss';
        $class = self::CONNECTORS[$type] ?? null;

        if ($class === null) {
            throw new FeedParseException("Connecteur « {$type} » non supporté.");
        }

        return $this->container->make($class);
    }
}
