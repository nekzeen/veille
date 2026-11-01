<?php

namespace Tests\Fakes;

use App\Services\Feed\Data\ParsedFeed;
use App\Services\Feed\FeedParserInterface;
use App\Services\Feed\SimplePieFeedParser;

/** Parseur réel instrumenté : compte les appels. */
final class SpyFeedParser implements FeedParserInterface
{
    public int $calls = 0;

    private readonly SimplePieFeedParser $inner;

    public function __construct()
    {
        $this->inner = new SimplePieFeedParser;
    }

    public function parse(string $xml): ParsedFeed
    {
        $this->calls++;

        return $this->inner->parse($xml);
    }
}
