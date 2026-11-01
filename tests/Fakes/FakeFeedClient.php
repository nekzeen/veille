<?php

namespace Tests\Fakes;

use App\Services\Feed\Data\FeedFetchResult;
use App\Services\Feed\FeedClientInterface;
use Throwable;

/**
 * Client de flux faké : renvoie un résultat prédéfini ou lève une
 * exception, et enregistre les appels (aucune E/S réseau).
 */
final class FakeFeedClient implements FeedClientInterface
{
    /** @var list<array{url: string, etag: ?string, lastModified: ?string}> */
    public array $calls = [];

    public function __construct(private readonly FeedFetchResult|Throwable $outcome) {}

    public static function ok(string $body, ?string $etag = null, ?string $lastModified = null): self
    {
        return new self(new FeedFetchResult(
            body: $body,
            statusCode: 200,
            url: 'https://exemple.fr/feed',
            effectiveUrl: 'https://exemple.fr/feed',
            etag: $etag,
            lastModified: $lastModified,
            notModified: false,
            bytesRead: strlen($body),
            redirects: 0,
        ));
    }

    public static function notModified(?string $etag = null, ?string $lastModified = null): self
    {
        return new self(new FeedFetchResult(
            body: '',
            statusCode: 304,
            url: 'https://exemple.fr/feed',
            effectiveUrl: 'https://exemple.fr/feed',
            etag: $etag,
            lastModified: $lastModified,
            notModified: true,
            bytesRead: 0,
            redirects: 0,
        ));
    }

    public function fetch(string $url, ?string $etag = null, ?string $lastModified = null): FeedFetchResult
    {
        $this->calls[] = ['url' => $url, 'etag' => $etag, 'lastModified' => $lastModified];

        if ($this->outcome instanceof Throwable) {
            throw $this->outcome;
        }

        return $this->outcome;
    }
}
