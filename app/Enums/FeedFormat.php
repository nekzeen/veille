<?php

namespace App\Enums;

use SimplePie\SimplePie;

/**
 * Famille de format d'un flux parsé — déterminée par SimplePie
 * (bitmask {@see SimplePie::TYPE_*}), jamais par le
 * Content-Type HTTP.
 */
enum FeedFormat: string
{
    case Rss = 'rss';
    case Rdf = 'rdf';
    case Atom = 'atom';
    case Unknown = 'unknown';

    public static function fromSimplePieType(int $type): self
    {
        $pie = SimplePie::class;

        return match (true) {
            (bool) ($type & $pie::TYPE_ATOM_ALL) => self::Atom,
            (bool) ($type & ($pie::TYPE_RSS_10 | $pie::TYPE_RSS_090)) => self::Rdf,
            (bool) ($type & $pie::TYPE_RSS_ALL) => self::Rss,
            default => self::Unknown,
        };
    }
}
