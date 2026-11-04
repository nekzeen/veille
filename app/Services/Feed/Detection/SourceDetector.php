<?php

declare(strict_types=1);

namespace App\Services\Feed\Detection;

use App\Enums\FeedFormat;
use App\Services\Feed\Data\FeedFetchResult;
use App\Services\Feed\Exceptions\FeedDnsException;
use App\Services\Feed\Exceptions\FeedFetchException;
use App\Services\Feed\Exceptions\FeedHttpException;
use App\Services\Feed\Exceptions\FeedParseException;
use App\Services\Feed\Exceptions\FeedRedirectException;
use App\Services\Feed\Exceptions\FeedUrlException;
use App\Services\Feed\FeedClientInterface;
use App\Services\Feed\FeedParserInterface;
use App\Services\Feed\FeedUrlValidator;
use App\Services\Feed\Html\HtmlExtractor;
use App\Services\Feed\JsonFeedParser;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

/**
 * Détection du type d'une URL soumise (M8.3) — produit une
 * proposition, ne persiste rien et ne modifie aucune source.
 *
 * Ordre (Architecture.md § M8) : 1. RSS/Atom ; 2. JSON Feed ;
 * puis, pour une page HTML : flux alternatif déclaré
 * (`<link rel="alternate">`, revérifié), 3. métadonnées structurées
 * (JSON-LD / Open Graph), 4-5. page HTML générique → connecteur HTML
 * (M8.4). Toute récupération passe par `FeedClientInterface`
 * (`SecureFeedClient` : URL, DNS/IP, redirections, taille, durée).
 * Aucun JavaScript exécuté ; une protection anti-robot est signalée
 * (`blocked`), jamais contournée.
 */
final class SourceDetector
{
    /** Flux alternatifs vérifiés au plus (borne réseau : 1 + 2 GET). */
    private const MAX_ALTERNATE_FETCHES = 2;

    private const MAX_ALTERNATES_LISTED = 5;

    private const XML_TYPES = ['application/rss+xml', 'application/atom+xml', 'application/rdf+xml', 'application/xml', 'text/xml'];

    private const JSON_TYPES = ['application/feed+json', 'application/json'];

    private const HTML_TYPES = ['text/html', 'application/xhtml+xml'];

    private const FEED_LINK_TYPES = ['application/rss+xml' => 'rss', 'application/atom+xml' => 'atom', 'application/feed+json' => 'json_feed', 'application/json' => 'json_feed'];

    private const JSONLD_TYPES = ['ItemList', 'Article', 'NewsArticle', 'BlogPosting', 'CollectionPage'];

    private const CHALLENGE_MARKERS = '/captcha|cf-challenge|challenge-platform|datadome|px-captcha/i';

    /** Une page de challenge n'a quasiment aucun texte visible. */
    private const CHALLENGE_MAX_TEXT = 3000;

    public function __construct(
        private readonly FeedClientInterface $client,
        private readonly FeedParserInterface $parser,
        private readonly FeedUrlValidator $validator,
        private readonly JsonFeedParser $jsonParser,
        private readonly HtmlExtractor $htmlExtractor,
    ) {}

    /**
     * @param  array<string, mixed>|null  $config  configuration HTML
     *                                             candidate (sélecteurs, stratégie) — prévisualisation
     *                                             et enregistrement l'utilisent pour rejouer l'extraction
     */
    public function detect(string $submittedUrl, ?array $config = null): DetectionResult
    {
        $submittedUrl = trim($submittedUrl);

        try {
            $this->validator->validate($submittedUrl);
            $response = $this->client->fetch($submittedUrl);
        } catch (FeedFetchException $e) {
            return DetectionResult::failure(self::failureStatus($e), $submittedUrl, self::failureDetails($e));
        }

        $kind = self::contentKind($response);

        return match ($kind) {
            'xml' => $this->fromXml($submittedUrl, $submittedUrl, $response, 'direct')
                ?? DetectionResult::failure('unrecognized', $submittedUrl),
            'json' => $this->fromJson($submittedUrl, $submittedUrl, $response->body, 'direct')
                ?? DetectionResult::failure('unrecognized', $submittedUrl),
            'html' => $this->fromHtml($submittedUrl, $response, $config),
            default => DetectionResult::failure('unsupported_content_type', $submittedUrl, ['content_type' => $kind]),
        };
    }

    private function fromXml(string $submittedUrl, string $fetchUrl, FeedFetchResult $response, string $via): ?DetectionResult
    {
        try {
            $parsed = $this->parser->parse($response->body);
        } catch (FeedParseException) {
            return null;
        }

        return new DetectionResult(
            status: 'detected',
            submittedUrl: $submittedUrl,
            fetchUrl: $fetchUrl,
            connector: $parsed->format === FeedFormat::Atom ? 'atom' : 'rss',
            confidence: 'high',
            configState: 'detected',
            connectorAvailable: true,
            details: ['format' => $parsed->format->value, 'via' => $via, 'title' => $parsed->title, 'item_count' => count($parsed->items)],
            parsed: $parsed,
        );
    }

    private function fromJson(string $submittedUrl, string $fetchUrl, string $body, string $via): ?DetectionResult
    {
        try {
            $parsed = $this->jsonParser->parse($body);
        } catch (FeedParseException) {
            return null;
        }

        return new DetectionResult(
            status: 'detected',
            submittedUrl: $submittedUrl,
            fetchUrl: $fetchUrl,
            connector: 'json_feed',
            confidence: 'high',
            configState: 'detected',
            connectorAvailable: true,
            details: ['format' => 'json_feed', 'via' => $via, 'title' => $parsed->title, 'item_count' => count($parsed->items)],
            parsed: $parsed,
        );
    }

    /** @param  array<string, mixed>|null  $config */
    private function fromHtml(string $submittedUrl, FeedFetchResult $response, ?array $config = null): DetectionResult
    {
        $dom = self::loadHtml($response->body);
        if ($dom === null) {
            return DetectionResult::failure('unrecognized', $submittedUrl);
        }

        $alternates = self::alternates($dom, $response->effectiveUrl);

        // Flux alternatif déclaré : revérifié par une vraie récupération
        // sécurisée — jamais proposé sur la seule foi de la balise.
        foreach (array_slice($alternates, 0, self::MAX_ALTERNATE_FETCHES) as $alternate) {
            try {
                $feed = $this->client->fetch($alternate['url']);
            } catch (FeedFetchException) {
                continue;
            }
            $result = $alternate['type'] === 'json_feed'
                ? $this->fromJson($submittedUrl, $alternate['url'], $feed->body, 'html_alternate')
                : $this->fromXml($submittedUrl, $alternate['url'], $feed, 'html_alternate');
            if ($result !== null) {
                return $result;
            }
        }

        if (self::looksLikeChallenge($dom, $response->body)) {
            return DetectionResult::failure('blocked', $submittedUrl);
        }

        $structured = self::structuredHints($dom);
        $warnings = [];
        $strategy = null;
        $parsed = $this->htmlExtractor->extract($response->body, $response->effectiveUrl, $config, $warnings, $strategy);
        $count = count($parsed->items);

        return new DetectionResult(
            status: 'needs_review',
            submittedUrl: $submittedUrl,
            fetchUrl: $submittedUrl,
            connector: 'html',
            confidence: $count >= 2 ? 'medium' : 'low',
            configState: 'needs_review',
            connectorAvailable: true,
            details: [
                'format' => 'html',
                'title' => self::pageTitle($dom),
                'structured_data' => $structured,
                'alternates_checked' => count($alternates),
                'extraction' => ['strategy' => $strategy, 'items' => $count],
            ],
            parsed: $parsed,
        );
    }

    /**
     * Famille de contenu : le `Content-Type` filtre, la racine du
     * document tranche (serveurs déclarant `text/html` pour un flux).
     */
    private static function contentKind(FeedFetchResult $response): string
    {
        $type = strtolower(trim(explode(';', (string) $response->contentType)[0]));
        $head = ltrim(substr($response->body, 0, 512), "\xEF\xBB\xBF \t\r\n");

        $allowed = $type === '' || $type === 'text/plain'
            || in_array($type, [...self::XML_TYPES, ...self::JSON_TYPES, ...self::HTML_TYPES], true);
        if (! $allowed) {
            return $type;
        }

        return match (true) {
            str_starts_with($head, '{') => 'json',
            preg_match('/^<\?xml|^<(rss|feed|rdf:RDF)[\s>]/i', $head) === 1
                && preg_match('/^<\?xml[^>]*>\s*<!doctype\s+html|<html[\s>]/i', $head) !== 1 => 'xml',
            in_array($type, self::JSON_TYPES, true) => 'json',
            in_array($type, self::XML_TYPES, true) => 'xml',
            default => 'html',
        };
    }

    private static function loadHtml(string $html): ?\DOMDocument
    {
        if (trim($html) === '') {
            return null;
        }
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $ok = $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $ok ? $dom : null;
    }

    /**
     * `<link rel="alternate" type="…">` reconnus, résolus en absolu,
     * RSS/Atom avant JSON Feed, http(s) uniquement, dédoublonnés.
     *
     * @return list<array{url: string, type: string}>
     */
    private static function alternates(\DOMDocument $dom, string $baseUrl): array
    {
        $found = [];
        foreach ($dom->getElementsByTagName('link') as $link) {
            $rel = preg_split('/\s+/', strtolower($link->getAttribute('rel'))) ?: [];
            $type = self::FEED_LINK_TYPES[strtolower(trim($link->getAttribute('type')))] ?? null;
            $href = trim($link->getAttribute('href'));
            if (! in_array('alternate', $rel, true) || $type === null || $href === '') {
                continue;
            }
            try {
                $url = (string) UriResolver::resolve(new Uri($baseUrl), new Uri($href));
            } catch (\InvalidArgumentException) {
                continue;
            }
            if (preg_match('#^https?://#i', $url) === 1 && ! isset($found[$url])) {
                $found[$url] = ['url' => $url, 'type' => $type];
            }
        }

        $list = array_values($found);
        usort($list, static fn (array $a, array $b): int => ($a['type'] === 'json_feed') <=> ($b['type'] === 'json_feed'));

        return array_slice($list, 0, self::MAX_ALTERNATES_LISTED);
    }

    /** @return list<string> types JSON-LD reconnus + `og:<type>` */
    private static function structuredHints(\DOMDocument $dom): array
    {
        $hints = [];
        foreach ($dom->getElementsByTagName('script') as $script) {
            if (strtolower(trim($script->getAttribute('type'))) !== 'application/ld+json') {
                continue;
            }
            $data = json_decode($script->textContent, true, 32);
            if (! is_array($data)) {
                continue;
            }
            $nodes = array_is_list($data) ? $data : (is_array($data['@graph'] ?? null) ? $data['@graph'] : [$data]);
            foreach ($nodes as $node) {
                foreach ((array) (is_array($node) ? ($node['@type'] ?? []) : []) as $type) {
                    if (is_string($type) && in_array($type, self::JSONLD_TYPES, true)) {
                        $hints[$type] = $type;
                    }
                }
            }
        }
        foreach ($dom->getElementsByTagName('meta') as $meta) {
            if (strtolower($meta->getAttribute('property')) === 'og:type' && $meta->getAttribute('content') !== '') {
                $hints['og'] = 'og:'.mb_substr($meta->getAttribute('content'), 0, 32);
            }
        }

        return array_values($hints);
    }

    private static function looksLikeChallenge(\DOMDocument $dom, string $html): bool
    {
        if (preg_match(self::CHALLENGE_MARKERS, $html) !== 1) {
            return false;
        }
        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return true;
        }
        foreach (['script', 'style', 'noscript'] as $tag) {
            foreach (iterator_to_array($body->getElementsByTagName($tag)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        return mb_strlen(trim((string) preg_replace('/\s+/u', ' ', $body->textContent))) < self::CHALLENGE_MAX_TEXT;
    }

    private static function pageTitle(\DOMDocument $dom): ?string
    {
        $title = trim((string) $dom->getElementsByTagName('title')->item(0)?->textContent);

        return $title === '' ? null : mb_substr($title, 0, 200);
    }

    private static function failureStatus(FeedFetchException $e): string
    {
        return match (true) {
            $e instanceof FeedUrlException => 'invalid_url',
            $e instanceof FeedDnsException, $e instanceof FeedRedirectException => 'refused',
            default => 'unreachable',
        };
    }

    /** @return array<string, int> code HTTP seul — aucun message technique exposé */
    private static function failureDetails(FeedFetchException $e): array
    {
        return $e instanceof FeedHttpException ? ['http_status' => $e->statusCode] : [];
    }
}
