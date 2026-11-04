<?php

declare(strict_types=1);

namespace App\Services\Feed\Html;

use App\Enums\FeedFormat;
use App\Services\Feed\ArticleLinkResolver;
use App\Services\Feed\Data\FeedItemData;
use App\Services\Feed\Data\ParsedFeed;
use App\Services\Feed\IpAddressPolicy;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

/**
 * Extraction d'articles d'une page HTML (M8.4) — pure, aucune E/S.
 *
 * Ordre des stratégies (Architecture.md § M8) :
 * 1. JSON-LD (`ItemList`, `Article`, `NewsArticle`…) ;
 * 2. Open Graph (page article uniquement, `og:type=article`) ;
 * 3. structures HTML répétitives (`<article>`, listes, blocs
 *    d'au moins trois liens de titre) ;
 * 4. sélecteurs CSS personnalisés (`config.selectors`) — dernier
 *    recours, ou seuls si `config.strategy='selectors'`.
 *
 * Une stratégie s'arrête au premier résultat non vide (≥ 1 article,
 * sauf les structures répétitives : ≥ 2 pour écarter les listes de
 * navigation). Champs absents → `null`, jamais inventés. Les liens
 * extraits ne sont retenus qu'en http(s) absolu et distincts de la
 * page elle-même — jamais fiables par défaut ; les doublons d'URL
 * sont ignorés. Aucun JavaScript n'est exécuté.
 *
 * `config` : `{version, strategy:'auto'|'selectors',
 * enrich_pages:0-3, selectors:{item,title,link,summary,date}}` —
 * `enrich_pages` est lu par `HtmlConnector` (borné à 3).
 */
final class HtmlExtractor
{
    public const MAX_ENRICH_PAGES = 3;

    /** Longueur minimale d'un texte de lien servant de titre. */
    private const MIN_LINK_TEXT = 8;

    private const SUMMARY_MAX = 600;

    private const JSONLD_ARTICLE_TYPES = ['Article', 'NewsArticle', 'BlogPosting', 'Report', 'ScholarlyArticle', 'TechArticle', 'SocialMediaPosting'];

    /**
     * @param  array<string, mixed>|null  $config
     * @param  list<string>  $warnings  stratégies tentées / anomalies (sortie)
     * @param  string|null  $strategy  stratégie retenue (sortie)
     */
    public function extract(string $html, string $baseUrl, ?array $config = null, array &$warnings = [], ?string &$strategy = null): ParsedFeed
    {
        $warnings = [];
        $dom = self::loadHtml($html);
        if ($dom === null) {
            return new ParsedFeed(FeedFormat::Html, null, null, null, null, [], ['Document HTML illisible.']);
        }

        $title = self::pageTitle($dom);
        $xpath = new \DOMXPath($dom);
        $selectors = self::selectors($config);
        $items = [];

        $strategies = self::strategy($config) === 'selectors'
            ? ['selectors']
            : ['jsonld', 'opengraph', 'repeated', 'selectors'];

        foreach ($strategies as $current) {
            $strategy = $current;
            if ($current === 'jsonld') {
                $raw = $this->fromJsonLd($dom, $baseUrl, $warnings);
            } elseif ($current === 'opengraph') {
                $raw = $this->fromOpenGraph($dom, $baseUrl);
            } elseif ($current === 'repeated') {
                $raw = $this->fromRepeated($dom, $xpath, $baseUrl);
            } else {
                $raw = $this->fromSelectors($dom, $xpath, $baseUrl, $selectors, $warnings);
            }
            $items = $this->dedupe($raw, $warnings);
            if ($items !== []) {
                break;
            }
        }

        return new ParsedFeed(
            format: FeedFormat::Html,
            title: $title,
            siteUrl: self::siteUrl($baseUrl),
            description: null,
            language: self::pageLang($dom),
            items: $items,
            warnings: $warnings,
        );
    }

    /**
     * Métadonnées d'une page article individuelle (enrichissement
     * borné) : JSON-LD puis Open Graph — jamais les structures
     * répétitives ni les sélecteurs.
     *
     * @return array{title:?string, url:?string, summary:?string, published_at:?CarbonImmutable, author:?string}
     */
    public function extractArticleMetadata(string $html, string $baseUrl): array
    {
        $empty = ['title' => null, 'url' => null, 'summary' => null, 'published_at' => null, 'author' => null];
        $dom = self::loadHtml($html);
        if ($dom === null) {
            return $empty;
        }

        $warnings = [];
        foreach ($this->articleNodes($dom) as $node) {
            $found = self::articleFromJsonLd($node, $baseUrl);
            if ($found !== []) {
                unset($found['id']);

                return $found;
            }
        }

        return $this->openGraphMeta($dom, $baseUrl, onlyArticle: false);
    }

    // ---------- Stratégie 1 : JSON-LD ----------

    /**
     * @param  list<string>  $warnings
     * @return list<FeedItemData>
     */
    private function fromJsonLd(\DOMDocument $dom, string $baseUrl, array &$warnings): array
    {
        $items = [];

        foreach ($this->articleNodes($dom) as $node) {
            $item = self::articleFromJsonLd($node, $baseUrl);
            if ($item !== []) {
                $items[] = self::makeItem($item['title'], $item['url'], $item['summary'], $item['published_at'], $item['author'], $item['url'] ?? $item['id'] ?? null);
            }
        }
        foreach ($this->listNodes($dom) as $node) {
            foreach ((array) ($node['itemListElement'] ?? []) as $element) {
                if (! is_array($element)) {
                    continue;
                }
                // ListItem {item: {…}|url} ou article direct.
                $target = is_array($element['item'] ?? null) ? $element['item']
                    : (self::hasArticleType($element) || isset($element['headline']) || isset($element['name']) ? $element : null);
                if ($target !== null) {
                    $found = self::articleFromJsonLd($target, $baseUrl);
                } else {
                    $url = is_string($element['item'] ?? null) ? self::absoluteUrl($element['item'], $baseUrl)
                        : (is_string($element['url'] ?? null) ? self::absoluteUrl($element['url'], $baseUrl) : null);
                    $found = ['title' => null, 'url' => $url, 'summary' => null, 'published_at' => null, 'author' => null, 'id' => null];
                }
                if (($found['url'] ?? null) !== null || ($found['title'] ?? null) !== null) {
                    $items[] = self::makeItem($found['title'], $found['url'], $found['summary'], $found['published_at'], $found['author'], $found['url'] ?? $found['id'] ?? null);
                }
            }
        }

        if ($items !== []) {
            $warnings[] = 'extraction : JSON-LD';
        }

        return $items;
    }

    /** @return list<array<string, mixed>> nœuds JSON-LD dont @type est un type d'article */
    private function articleNodes(\DOMDocument $dom): array
    {
        $found = [];
        foreach (self::jsonLdNodes($dom) as $node) {
            if (is_array($node) && self::hasArticleType($node)) {
                $found[] = $node;
            }
        }

        return $found;
    }

    /** @return list<array<string, mixed>> nœuds JSON-LD de type ItemList */
    private function listNodes(\DOMDocument $dom): array
    {
        $found = [];
        foreach (self::jsonLdNodes($dom) as $node) {
            if (! is_array($node)) {
                continue;
            }
            $types = array_map('strval', (array) ($node['@type'] ?? []));
            if (in_array('ItemList', $types, true) && is_array($node['itemListElement'] ?? null)) {
                $found[] = $node;
            }
        }

        return $found;
    }

    /**
     * Tous les objets JSON-LD des blocs `application/ld+json` : racines,
     * tableaux, et nœuds de `@graph` — un niveau suffit pour les
     * documents réels.
     *
     * @return list<mixed>
     */
    private static function jsonLdNodes(\DOMDocument $dom): array
    {
        $nodes = [];
        foreach ($dom->getElementsByTagName('script') as $script) {
            if (strtolower(trim($script->getAttribute('type'))) !== 'application/ld+json') {
                continue;
            }
            $data = json_decode($script->textContent, true, 32);
            if (! is_array($data)) {
                continue;
            }
            $roots = array_is_list($data) ? $data : [$data];
            foreach ($roots as $root) {
                if (! is_array($root)) {
                    continue;
                }
                $nodes[] = $root;
                if (is_array($root['@graph'] ?? null)) {
                    array_push($nodes, ...$root['@graph']);
                }
            }
        }

        return $nodes;
    }

    /** @param  array<string, mixed>  $node */
    private static function hasArticleType(array $node): bool
    {
        $types = array_map('strval', (array) ($node['@type'] ?? []));

        return array_intersect($types, self::JSONLD_ARTICLE_TYPES) !== [];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{title:?string, url:?string, summary:?string, published_at:?CarbonImmutable, author:?string, id:?string}|array{}
     */
    private static function articleFromJsonLd(array $node, string $baseUrl): array
    {
        $title = self::str($node['headline'] ?? $node['name'] ?? null);
        $url = self::str($node['url'] ?? null)
            ?? self::str(is_array($node['mainEntityOfPage'] ?? null) ? $node['mainEntityOfPage']['@id'] ?? null : ($node['mainEntityOfPage'] ?? null));
        $url = $url !== null ? self::absoluteUrl($url, $baseUrl) : null;

        $author = null;
        $authors = $node['author'] ?? $node['creator'] ?? [];
        foreach (is_array($authors) && array_is_list($authors) ? $authors : [$authors] as $a) {
            $name = is_array($a) ? self::str($a['name'] ?? null) : self::str($a);
            if ($name !== null) {
                $author = $name;
                break;
            }
        }

        if ($title === null && $url === null) {
            return [];
        }

        return [
            'title' => $title,
            'url' => $url,
            'summary' => self::str($node['description'] ?? null),
            'published_at' => self::date($node['datePublished'] ?? $node['dateCreated'] ?? null),
            'author' => $author,
            'id' => self::str($node['@id'] ?? null),
        ];
    }

    // ---------- Stratégie 2 : Open Graph (page article) ----------

    /** @return list<FeedItemData> */
    private function fromOpenGraph(\DOMDocument $dom, string $baseUrl): array
    {
        $meta = $this->openGraphMeta($dom, $baseUrl, onlyArticle: true);
        if ($meta['title'] === null && $meta['url'] === null) {
            return [];
        }

        return [self::makeItem($meta['title'], $meta['url'], $meta['summary'], $meta['published_at'], $meta['author'], $meta['url'])];
    }

    /**
     * @return array{title:?string, url:?string, summary:?string, published_at:?CarbonImmutable, author:?string}
     */
    private function openGraphMeta(\DOMDocument $dom, string $baseUrl, bool $onlyArticle): array
    {
        $empty = ['title' => null, 'url' => null, 'summary' => null, 'published_at' => null, 'author' => null];
        $meta = [];
        foreach ($dom->getElementsByTagName('meta') as $tag) {
            $name = strtolower($tag->getAttribute('property') !== '' ? $tag->getAttribute('property') : $tag->getAttribute('name'));
            if ($name !== '' && ! isset($meta[$name])) {
                $meta[$name] = $tag->getAttribute('content');
            }
        }

        if ($onlyArticle && strtolower(trim($meta['og:type'] ?? '')) !== 'article') {
            return $empty;
        }

        $url = self::str($meta['og:url'] ?? null);
        $url = self::absoluteUrl($url ?? $baseUrl, $baseUrl);
        $title = self::str($meta['og:title'] ?? null);
        if (! $onlyArticle && $title === null) {
            $title = self::pageTitle($dom);
        }
        if ($title === null && $url === null) {
            return $empty;
        }

        return [
            'title' => $title,
            'url' => $url,
            'summary' => self::str($meta['og:description'] ?? $meta['description'] ?? null),
            'published_at' => self::date($meta['article:published_time'] ?? $meta['date'] ?? null),
            'author' => self::str($meta['article:author'] ?? $meta['author'] ?? null),
        ];
    }

    // ---------- Stratégie 3 : structures répétitives ----------

    /**
     * `<article>` d'abord, puis conteneur de liste (`ul`/`ol`), puis
     * regroupement par frères de même balise contenant des liens.
     * Exige ≥ 2 articles pour écarter les menus et barres latérales.
     *
     * @return list<FeedItemData>
     */
    private function fromRepeated(\DOMDocument $dom, \DOMXPath $xpath, string $baseUrl): array
    {
        $articles = $xpath->query('//article');
        if ($articles !== false && $articles->length >= 2) {
            $items = [];
            foreach ($articles as $article) {
                if ($article instanceof \DOMElement
                    && ($item = self::itemFromBlock($article, $xpath, $baseUrl))) {
                    $items[] = $item;
                }
            }
            if (count($items) >= 2) {
                return $items;
            }
        }

        return $this->repeatedBlocks($xpath, $baseUrl, $xpath->query('//ul|//ol') ?: null)
            ?? $this->genericRepeated($dom, $xpath, $baseUrl)
            ?? [];
    }

    /** @return list<FeedItemData>|null */
    private function repeatedBlocks(\DOMXPath $xpath, string $baseUrl, ?\DOMNodeList $containers): ?array
    {
        $best = [];
        foreach ($containers ?: [] as $container) {
            if (! $container instanceof \DOMElement) {
                continue;
            }
            $children = self::elementChildren($container);
            if (count($children) < 3) {
                continue;
            }
            $items = [];
            foreach ($children as $child) {
                if ($item = self::itemFromBlock($child, $xpath, $baseUrl)) {
                    $items[] = $item;
                }
            }
            if (count($items) > count($best)) {
                $best = $items;
            }
        }

        return count($best) >= 3 ? $best : null;
    }

    /**
     * Frères de même balise (≥ 3) contenant chacun un lien de titre :
     * conteneur le plus riche, à égalité le premier du document.
     *
     * @return list<FeedItemData>|null
     */
    private function genericRepeated(\DOMDocument $dom, \DOMXPath $xpath, string $baseUrl): ?array
    {
        $best = [];
        foreach ($xpath->query('//body|//body//*') ?: [] as $parent) {
            if (! $parent instanceof \DOMElement) {
                continue;
            }
            $byTag = [];
            foreach (self::elementChildren($parent) as $child) {
                $byTag[$child->tagName][] = $child;
            }
            foreach ($byTag as $siblings) {
                if (count($siblings) < 3) {
                    continue;
                }
                $items = [];
                foreach ($siblings as $block) {
                    if ($item = self::itemFromBlock($block, $xpath, $baseUrl)) {
                        $items[] = $item;
                    }
                }
                if (count($items) > count($best)) {
                    $best = $items;
                }
            }
        }

        return count($best) >= 3 ? $best : null;
    }

    /**
     * Bloc → article : titre = en-tête ou texte du lien ; résumé =
     * premier paragraphe ; date = `<time datetime>` ; lien = URL de
     * titre qualifiée (http(s), ≠ page, texte suffisant).
     */
    private static function itemFromBlock(\DOMElement $block, \DOMXPath $xpath, string $baseUrl): ?FeedItemData
    {
        $heading = null;
        foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $tag) {
            $found = $xpath->query('.//'.$tag, $block);
            if ($found !== false && $found->length > 0) {
                $heading = $found->item(0);
                break;
            }
        }
        $scope = $heading instanceof \DOMElement ? $heading : $block;

        // Lien de titre : dans l'en-tête si possible, sinon premier
        // lien dont le texte est suffisamment long.
        $candidates = [];
        foreach ($xpath->query('.//a[@href]', $scope) ?: [] as $a) {
            if ($a instanceof \DOMElement
                && ($url = self::absoluteUrl($a->getAttribute('href'), $baseUrl)) !== null
                && $url !== $baseUrl) {
                $candidates[] = [$a, $url];
            }
        }
        if ($candidates === [] && $heading instanceof \DOMElement) {
            foreach ($xpath->query('.//a[@href]', $block) ?: [] as $a) {
                if ($a instanceof \DOMElement
                    && ($url = self::absoluteUrl($a->getAttribute('href'), $baseUrl)) !== null
                    && $url !== $baseUrl) {
                    $candidates[] = [$a, $url];
                }
            }
        }

        foreach ($candidates as [$a, $url]) {
            $title = $heading instanceof \DOMElement
                ? self::cleanText($heading->textContent, 300)
                : self::cleanText($a->textContent, 300);
            if (mb_strlen($title ?? '') >= self::MIN_LINK_TEXT) {
                $summary = null;
                $time = null;
                $paragraphs = $xpath->query('.//p', $block);
                $p = $paragraphs !== false ? $paragraphs->item(0) : null;
                if ($p instanceof \DOMElement) {
                    $summary = self::cleanText($p->textContent, self::SUMMARY_MAX);
                }
                $times = $xpath->query('.//time', $block);
                $t = $times !== false ? $times->item(0) : null;
                if ($t instanceof \DOMElement) {
                    $time = self::date($t->getAttribute('datetime') !== '' ? $t->getAttribute('datetime') : $t->textContent);
                }

                return self::makeItem($title, $url, $summary, $time, null, $url);
            }
        }

        return null;
    }

    // ---------- Stratégie 4 : sélecteurs CSS ----------

    /**
     * Sous-ensemble de sélecteurs : `tag`, `.classe`, `#id`,
     * `[attr]`, `[attr="v"]`, combinés et descendants/enfants.
     *
     * @param  array{item:?string,title:?string,link:?string,summary:?string,date:?string}  $selectors
     * @return list<FeedItemData>
     */
    /**
     * @param  array{item:?string,title:?string,link:?string,summary:?string,date:?string}  $selectors
     * @param  list<string>  $warnings
     * @return list<FeedItemData>
     */
    private function fromSelectors(\DOMDocument $dom, \DOMXPath $xpath, string $baseUrl, array $selectors, array &$warnings): array
    {
        if ($selectors['item'] === null || $selectors['item'] === '') {
            return [];
        }
        $itemQuery = self::cssToXpath($selectors['item']);
        if ($itemQuery === null) {
            $warnings[] = 'sélecteur « item » invalide : '.$selectors['item'];

            return [];
        }

        $items = [];
        foreach ($xpath->query($itemQuery) ?: [] as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }
            $title = self::textIn($node, $xpath, $selectors['title']);
            $anchor = self::nodeIn($node, $xpath, $selectors['link']);
            $anchor = $anchor instanceof \DOMElement && $anchor->tagName === 'a' ? $anchor
                : ($anchor instanceof \DOMElement ? $xpath->query('.//a[@href]', $anchor)->item(0) : null);
            $anchor = $anchor instanceof \DOMElement ? $anchor : $xpath->query('.//a[@href]', $node)->item(0);
            $url = $anchor instanceof \DOMElement ? self::absoluteUrl($anchor->getAttribute('href'), $baseUrl) : null;
            $summary = self::textIn($node, $xpath, $selectors['summary']);
            $dateNode = self::nodeIn($node, $xpath, $selectors['date']);
            $date = null;
            if ($dateNode instanceof \DOMElement) {
                $date = self::date($dateNode->getAttribute('datetime') !== '' ? $dateNode->getAttribute('datetime') : $dateNode->textContent);
            }
            $title = $title ?? self::linkText($xpath, $node);

            $items[] = self::makeItem($title, $url, $summary !== null ? mb_substr($summary, 0, self::SUMMARY_MAX) : null, $date, null, $url);
        }
        if ($items !== []) {
            $warnings[] = 'extraction : sélecteurs';
        }

        return $items;
    }

    /** Vrai si le sélecteur CSS appartient au sous-ensemble pris en charge. */
    public static function isValidSelector(string $selector): bool
    {
        return self::cssToXpath($selector) !== null;
    }

    /**
     * CSS → XPath (fragment relatif ou absolu). `null` = sélecteur
     * hors du sous-ensemble pris en charge.
     */
    private static function cssToXpath(string $selector): ?string
    {
        $selector = trim($selector);
        if ($selector === '') {
            return null;
        }

        $segments = [];
        foreach (explode('>', $selector) as $childPart) {
            $compounds = preg_split('/\s+/', trim($childPart));
            $steps = [];
            foreach ($compounds ?: [] as $token) {
                $step = self::compoundToXpath($token);
                if ($step === null) {
                    return null;
                }
                $steps[] = $step;
            }
            if ($steps === []) {
                return null;
            }
            $segments[] = implode('//', $steps);
        }

        return '//'.implode('/', $segments);
    }

    /** Sélecteur simple (`tag.classe#id[attr="v"]`) → `tag[prédicats]`. */
    private static function compoundToXpath(string $token): ?string
    {
        if ($token === '') {
            return null;
        }
        // `.classe`/`#id` sans balise → `*`.
        if (preg_match('/^[a-zA-Z*][a-zA-Z0-9-]*/', $token, $m)) {
            $tag = $m[0] === '*' ? '*' : strtolower($m[0]);
            $rest = substr($token, strlen($m[0]));
        } elseif (str_starts_with($token, '.') || str_starts_with($token, '#')) {
            $tag = '*';
            $rest = $token;
        } else {
            return null;
        }
        if (preg_replace('/([.#][a-zA-Z0-9_-]+|\[[^\]]*\])/', '', $rest) !== '') {
            return null;
        }
        $predicate = '';
        preg_match_all('/([.#])([a-zA-Z0-9_-]+)|\[([a-zA-Z-]+)(?:=["\']?([^"\'\]]*)["\']?)?\]/', $rest, $parts, PREG_SET_ORDER);
        foreach ($parts as $part) {
            if (($part[1] ?? '') === '.') {
                $predicate .= sprintf("[contains(concat(' ', normalize-space(@class), ' '), ' %s ')]", $part[2]);
            } elseif (($part[1] ?? '') === '#') {
                $predicate .= "[@id='{$part[2]}']";
            } elseif (($part[1] ?? '') === '') {
                $attr = strtolower($part[3]);
                $predicate .= ($part[4] ?? '') === '' ? "[@{$attr}]" : "[@{$attr}='{$part[4]}']";
            }
        }

        return $tag.$predicate;
    }

    // ---------- Helpers ----------

    /**
     * @param  list<FeedItemData>  $raw
     * @param  list<string>  $warnings
     */
    private function dedupe(array $raw, array &$warnings): array
    {
        $items = [];
        $seen = [];
        foreach ($raw as $item) {
            if ($item->url === null || ($item->title === null || trim($item->title) === '')) {
                if ($item->url !== null || $item->title !== null) {
                    $warnings[] = 'article incomplet ignoré (titre ou URL absent).';
                }

                continue;
            }
            $key = mb_strtolower($item->url);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $items[] = $item;
        }

        return $items;
    }

    private static function makeItem(?string $title, ?string $url, ?string $summary, ?CarbonImmutable $date, ?string $author, ?string $guid): FeedItemData
    {
        return new FeedItemData(
            guid: $guid,
            guidIsPermalink: $guid !== null,
            url: ArticleLinkResolver::resolve($url),
            title: $title,
            author: $author,
            summary: $summary,
            content: null,
            publishedAt: $date,
            updatedAt: null,
        );
    }

    private static function absoluteUrl(string $href, string $baseUrl): ?string
    {
        $href = trim($href);
        if ($href === '' || preg_match('/^(javascript|mailto|tel|data|#)/i', $href) === 1) {
            return null;
        }
        try {
            $url = (string) UriResolver::resolve(new Uri($baseUrl), new Uri($href));
        } catch (\InvalidArgumentException) {
            return null;
        }
        $url = explode('#', $url)[0];
        if (preg_match('#^https?://#i', $url) !== 1) {
            return null;
        }

        // Un hôte en littéral IP non public n'est jamais un lien
        // d'article valide (les noms de domaine sont vérifiés à la
        // récupération, par le client sécurisé).
        $host = parse_url($url, PHP_URL_HOST);
        if (is_string($host) && filter_var($host, FILTER_VALIDATE_IP) !== false
            && ! self::ipPolicy()->isGloballyRoutable($host)) {
            return null;
        }

        return $url;
    }

    private static function ipPolicy(): IpAddressPolicy
    {
        static $policy = null;

        return $policy ??= new IpAddressPolicy;
    }

    private static function cleanText(string $text, int $max): ?string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $text));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private static function textIn(\DOMElement $node, \DOMXPath $xpath, ?string $selector): ?string
    {
        $target = self::nodeIn($node, $xpath, $selector);
        if (! $target instanceof \DOMElement) {
            return null;
        }
        $value = trim((string) preg_replace('/\s+/u', ' ', $target->textContent));

        return $value === '' ? null : $value;
    }

    private static function nodeIn(\DOMElement $node, \DOMXPath $xpath, ?string $selector): ?\DOMElement
    {
        if ($selector === null || $selector === '') {
            return null;
        }
        $query = self::cssToXpath($selector);
        if ($query === null) {
            return null;
        }
        $found = $xpath->query('.'.$query, $node);
        if ($found === false || $found->length === 0) {
            return null;
        }
        $candidate = $found->item(0);

        return $candidate instanceof \DOMElement ? $candidate : null;
    }

    private static function linkText(\DOMXPath $xpath, \DOMElement $node): ?string
    {
        $a = $xpath->query('.//a[@href]', $node)->item(0);
        if (! $a instanceof \DOMElement) {
            return null;
        }
        $value = trim((string) preg_replace('/\s+/u', ' ', $a->textContent));

        return $value === '' ? null : mb_substr($value, 0, 300);
    }

    private static function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        $value = self::str($value);
        if ($value === null) {
            return null;
        }
        try {
            return (new CarbonImmutable($value))->utc();
        } catch (\Throwable) {
            return null;
        }
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

    private static function pageTitle(\DOMDocument $dom): ?string
    {
        $title = trim((string) $dom->getElementsByTagName('title')->item(0)?->textContent);

        return $title === '' ? null : mb_substr($title, 0, 200);
    }

    private static function pageLang(\DOMDocument $dom): ?string
    {
        $lang = trim($dom->documentElement?->getAttribute('lang') ?? '');

        return $lang === '' ? null : $lang;
    }

    private static function siteUrl(string $baseUrl): ?string
    {
        $parts = parse_url($baseUrl);

        return isset($parts['scheme'], $parts['host'])
            ? $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')
            : null;
    }

    /** @return list<\DOMElement> */
    private static function elementChildren(\DOMElement $parent): array
    {
        return array_values(array_filter(
            iterator_to_array($parent->childNodes),
            static fn ($n): bool => $n instanceof \DOMElement,
        ));
    }

    private static function strategy(?array $config): string
    {
        $strategy = is_array($config) ? ($config['strategy'] ?? 'auto') : 'auto';

        return $strategy === 'selectors' ? 'selectors' : 'auto';
    }

    /** @return array{item:?string,title:?string,link:?string,summary:?string,date:?string} */
    private static function selectors(?array $config): array
    {
        $s = is_array($config['selectors'] ?? null) ? $config['selectors'] : [];

        return [
            'item' => self::str($s['item'] ?? null),
            'title' => self::str($s['title'] ?? null),
            'link' => self::str($s['link'] ?? null),
            'summary' => self::str($s['summary'] ?? null),
            'date' => self::str($s['date'] ?? null),
        ];
    }
}
