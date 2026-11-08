<?php

namespace App\Services\Feed;

use App\Enums\FeedFormat;
use App\Services\Feed\Data\FeedItemData;
use App\Services\Feed\Data\ParsedFeed;
use App\Services\Feed\Exceptions\FeedParseException;
use Carbon\CarbonImmutable;
use SimplePie\Item;
use SimplePie\SimplePie;

/**
 * Adaptateur SimplePie : transforme un document XML brut en
 * {@see ParsedFeed} normalisé.
 *
 * Isolation réseau garantie : le XML arrive exclusivement via
 * {@see SimplePie::set_raw_data()} et le cache est désactivé — le
 * fetcher HTTP interne de SimplePie n'est jamais sollicité, aucune
 * E/S disque ou réseau n'a lieu ici.
 */
final class SimplePieFeedParser implements FeedParserInterface
{
    private bool $lastRecovered = false;

    /** Vrai si le dernier parse a réussi après réparation XML (M12.17). */
    public function lastRecovered(): bool
    {
        return $this->lastRecovered;
    }

    private function buildPie(string $xml): SimplePie
    {
        $pie = new SimplePie;
        $pie->enable_cache(false);
        // Ordre du document conservé — le tri chronologique est une
        // affaire d'affichage, pas du parseur d'un collecteur.
        $pie->enable_order_by_date(false);
        $pie->set_raw_data($xml);

        return $pie;
    }

    public function parse(string $xml): ParsedFeed
    {
        $xml = ltrim($xml);
        if (str_starts_with($xml, "\xEF\xBB\xBF")) {
            $xml = substr($xml, 3);
        }
        if ($xml === '') {
            throw new FeedParseException('Document XML vide.');
        }

        $this->lastRecovered = false;
        $pie = $this->buildPie($xml);

        if (! $pie->init()) {
            // M12.17 — seconde passe conservative : uniquement si le
            // contenu brut présente des défauts sûrs à retirer (UTF-8
            // illégal / caractères interdits XML 1.0). Jamais de
            // reformatage ni de suppression de balises.
            $firstError = $this->simplePieError($pie);
            $repaired = XmlFeedRepair::repair($xml);
            if ($repaired === null) {
                throw new FeedParseException('Document XML non exploitable : '.$firstError);
            }
            $pie = $this->buildPie($repaired);
            if (! $pie->init()) {
                throw new FeedParseException('Document XML non exploitable : '.$firstError.' [réparation tentée, toujours illisible]');
            }
            $this->lastRecovered = true;
            $xml = $repaired;
        }

        $format = FeedFormat::fromSimplePieType($pie->get_type());
        if ($format === FeedFormat::Unknown) {
            throw new FeedParseException('Le document XML n\'est pas un flux RSS/Atom.');
        }

        $warnings = [];
        $items = [];
        $position = 0;
        foreach ($pie->get_items() as $item) {
            $items[] = $this->mapItem($item, $position, $warnings);
            $position++;
        }

        return new ParsedFeed(
            format: $format,
            title: self::blank($pie->get_title()),
            siteUrl: self::blank($pie->get_link()),
            description: self::blank($pie->get_description()),
            language: self::blank($pie->get_language()),
            items: $items,
            warnings: $warnings,
        );
    }

    private function mapItem(Item $item, int $position, array &$warnings): FeedItemData
    {
        $guid = self::blank($item->get_id(false, false));
        // get_link() uniquement : liens rel=alternate, <link> RSS ou
        // guid permalien — jamais get_permalink(), qui retombe sur
        // l'URL de la première enclosure quand aucun lien n'existe
        // (une enclosure n'est pas l'URL de l'article, §4.B).
        $url = self::blank($item->get_link());
        $title = self::blank($item->get_title());

        if ($guid === null) {
            $warnings[] = "Article #{$position} : identifiant absent.";
        }
        if ($url === null) {
            $warnings[] = "Article #{$position} : URL absente.";
        }
        if ($title === null) {
            $warnings[] = "Article #{$position} : titre absent.";
        }

        return new FeedItemData(
            guid: $guid,
            guidIsPermalink: $this->guidIsPermalink($item, $guid),
            url: ArticleLinkResolver::resolve($url),
            title: $title,
            author: self::blank($item->get_author()?->get_name()),
            summary: self::blank($item->get_description()),
            content: self::blank($item->get_content()),
            publishedAt: $this->publishedDate($item, $position, $warnings),
            updatedAt: $this->updatedDate($item, $position, $warnings),
        );
    }

    /**
     * Date de publication en UTC — uniquement si une balise la déclare
     * explicitement : `get_date()` de SimplePie retombe sinon sur
     * `<updated>`, ce qui ferait passer une date de mise à jour pour
     * une publication (faux-positif gênant pour le fallback §4.B.3).
     */
    private function publishedDate(Item $item, int $position, array &$warnings): ?CarbonImmutable
    {
        $declared = (bool) (
            $item->get_item_tags(SimplePie::NAMESPACE_ATOM_10, 'published')
            ?: $item->get_item_tags(SimplePie::NAMESPACE_RSS_20, 'pubDate')
            ?: $item->get_item_tags(SimplePie::NAMESPACE_DC_11, 'date')
            ?: $item->get_item_tags(SimplePie::NAMESPACE_DC_10, 'date')
            ?: $item->get_item_tags(SimplePie::NAMESPACE_ATOM_03, 'issued')
            ?: $item->get_item_tags(SimplePie::NAMESPACE_ATOM_03, 'created')
        );

        if (! $declared) {
            return null;
        }

        return $this->parsedDate(
            $item->get_date('U'),
            $item->get_date(''),
            "Article #{$position} : date de publication illisible",
            $warnings,
        );
    }

    /**
     * Date de mise à jour en UTC (Atom `<updated>`), null sinon.
     */
    private function updatedDate(Item $item, int $position, array &$warnings): ?CarbonImmutable
    {
        return $this->parsedDate(
            $item->get_updated_date('U'),
            $item->get_updated_date(''),
            "Article #{$position} : date de mise à jour illisible",
            $warnings,
        );
    }

    /**
     * Timestamp epoch (déjà interprété par SimplePie — pas
     * d'ambiguïté de fuseau) → CarbonImmutable UTC ; null si absent ou
     * illisible, le cas illisible étant signalé en warning.
     */
    private function parsedDate(mixed $ts, mixed $raw, string $warningPrefix, array &$warnings): ?CarbonImmutable
    {
        if (is_int($ts)) {
            return CarbonImmutable::createFromTimestampUTC($ts);
        }

        if (is_string($raw) && trim($raw) !== '') {
            $warnings[] = $warningPrefix.' (« '.mb_strimwidth(trim($raw), 0, 60, '…').' »).';
        }

        return null;
    }

    /**
     * `guid` exploitable comme identifiant de déduplication (§4.B.3) :
     * - Atom `<id>` : identifiant permanent par spécification → true ;
     * - RSS `<guid>` : attribut isPermaLink — absent → true (défaut
     *   de la spec RSS 2.0), "true" → true, "false" → false ;
     * - autre identifiant déclaré (dc:identifier, rdf:about) → true ;
     * - aucun identifiant → false (guid null).
     */
    private function guidIsPermalink(Item $item, ?string $guid): bool
    {
        if ($guid === null) {
            return false;
        }

        if ($item->get_item_tags(SimplePie::NAMESPACE_ATOM_10, 'id')
            || $item->get_item_tags(SimplePie::NAMESPACE_ATOM_03, 'id')) {
            return true;
        }

        if ($tags = $item->get_item_tags(SimplePie::NAMESPACE_RSS_20, 'guid')) {
            $attr = $tags[0]['attribs']['']['isPermaLink'] ?? null;

            return $attr === null || strtolower(trim((string) $attr)) === 'true';
        }

        return true;
    }

    private static function blank(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function simplePieError(SimplePie $pie): string
    {
        $error = $pie->error();
        $message = is_array($error) ? implode('; ', $error) : ($error ?? 'erreur inconnue');

        return mb_strimwidth($message, 0, 200, '…');
    }
}
