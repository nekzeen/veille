<?php

namespace Tests\Unit;

use App\Enums\FeedFormat;
use App\Services\Feed\Exceptions\FeedParseException;
use App\Services\Feed\SimplePieFeedParser;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SimplePieFeedParser::class)]
class SimplePieFeedParserTest extends TestCase
{
    private SimplePieFeedParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new SimplePieFeedParser;
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../fixtures/feeds/'.$name);
    }

    public function test_rss20_feed_metadata_and_items(): void
    {
        $feed = $this->parser->parse($this->fixture('rss20_basic.xml'));

        $this->assertSame(FeedFormat::Rss, $feed->format);
        $this->assertSame("Flux d'exemple", $feed->title);
        // SimplePie canonise l'IRI : le slash racine est ajouté.
        $this->assertSame('https://www.exemple.fr/', $feed->siteUrl);
        $this->assertSame('Le flux de démonstration', $feed->description);
        $this->assertSame('fr-FR', $feed->language);
        $this->assertCount(2, $feed->items);
        $this->assertSame([], $feed->warnings);

        $first = $feed->items[0];
        $this->assertSame('https://www.exemple.fr/articles/1', $first->guid);
        $this->assertTrue($first->guidIsPermalink);
        $this->assertSame('https://www.exemple.fr/articles/1', $first->url);
        $this->assertSame('Article complet', $first->title);
        $this->assertSame('Alice Martin', $first->author);
        $this->assertSame('Résumé du premier article.', $first->summary);
        $this->assertStringContainsString('<strong>complet</strong>', (string) $first->content);
        // 10:00 +0200 = 08:00 UTC : le fuseau déclaré est interprété.
        $this->assertInstanceOf(CarbonImmutable::class, $first->publishedAt);
        $this->assertSame('2026-10-30 08:00:00', $first->publishedAt->format('Y-m-d H:i:s'));
        $this->assertTrue($first->publishedAt->isUtc());

        $second = $feed->items[1];
        $this->assertSame('item-42-abc', $second->guid);
        $this->assertFalse($second->guidIsPermalink);
        $this->assertSame('https://www.exemple.fr/articles/2', $second->url);
    }

    public function test_rss20_guid_variants(): void
    {
        $items = $this->parser->parse($this->fixture('rss20_guid_variants.xml'))->items;
        $this->assertCount(6, $items);

        // isPermaLink="true" + <link> : identifiant et URL distincts.
        $this->assertTrue($items[0]->guidIsPermalink);
        $this->assertSame('https://www.exemple.fr/a1', $items[0]->guid);
        $this->assertSame('https://www.exemple.fr/a1', $items[0]->url);

        // isPermaLink="false" : identifiant conservé mais non exploitable.
        $this->assertSame('opaque-id-77', $items[1]->guid);
        $this->assertFalse($items[1]->guidIsPermalink);
        $this->assertSame('https://www.exemple.fr/a2', $items[1]->url);

        // Attribut absent : défaut de la spec RSS = true.
        $this->assertTrue($items[2]->guidIsPermalink);

        // Aucun guid déclaré : null, jamais synthétisé.
        $this->assertNull($items[3]->guid);
        $this->assertFalse($items[3]->guidIsPermalink);
        $this->assertSame('https://www.exemple.fr/a4', $items[3]->url);

        // guid permalien sans <link> : le guid fait office d'URL.
        $this->assertTrue($items[4]->guidIsPermalink);
        $this->assertSame('https://www.exemple.fr/a5', $items[4]->url);

        // guid non permalien sans <link> : aucune URL.
        $this->assertFalse($items[5]->guidIsPermalink);
        $this->assertNull($items[5]->url);
    }

    public function test_rss20_edge_cases_warn_but_do_not_fail(): void
    {
        $feed = $this->parser->parse($this->fixture('rss20_edge_cases.xml'));
        $this->assertCount(4, $feed->items);

        // Entités et caractères spéciaux décodés.
        $this->assertStringContainsString('éàü', (string) $feed->items[0]->title);
        $this->assertStringContainsString('🌍', (string) $feed->items[0]->title);

        // Date illisible → null + warning (pas d'échec du flux).
        $this->assertNull($feed->items[1]->publishedAt);
        $this->assertNotEmpty(
            array_filter($feed->warnings, fn (string $w) => str_contains($w, 'illisible')),
        );

        // Titre absent → null + warning ; le reste de l'article est extrait.
        $this->assertNull($feed->items[2]->title);
        $this->assertSame('https://www.exemple.fr/sans-titre', $feed->items[2]->url);

        // Article minimal : guid non permalien, aucune URL.
        $this->assertSame('spec-4', $feed->items[3]->guid);
        $this->assertFalse($feed->items[3]->guidIsPermalink);
        $this->assertNull($feed->items[3]->url);
        $this->assertNull($feed->items[3]->publishedAt);
    }

    public function test_rss20_namespaced_fields(): void
    {
        $feed = $this->parser->parse($this->fixture('rss20_namespaced.xml'));
        $item = $feed->items[0];

        // dc:identifier = identifiant déclaré exploitable.
        $this->assertSame('urn:blog:2026:dc', $item->guid);
        $this->assertTrue($item->guidIsPermalink);
        $this->assertSame('Rédaction', $item->author);
        // dc:date 14:30 +02:00 = 12:30 UTC.
        $this->assertSame('2026-10-29 12:30:00', $item->publishedAt->format('Y-m-d H:i:s'));
        // content:encoded complet et HTML conservé brut.
        $this->assertStringContainsString('<h2>Titre HTML</h2>', (string) $item->content);
    }

    public function test_rdf10_feed(): void
    {
        $feed = $this->parser->parse($this->fixture('rdf10_valid.xml'));

        $this->assertSame(FeedFormat::Rdf, $feed->format);
        $this->assertSame('Flux RDF', $feed->title);
        $this->assertSame('https://www.exemple.fr/', $feed->siteUrl);
        $this->assertCount(2, $feed->items);

        // rdf:about = identifiant déclaré exploitable.
        $this->assertSame('https://www.exemple.fr/rdf1', $feed->items[0]->guid);
        $this->assertTrue($feed->items[0]->guidIsPermalink);
        $this->assertSame('Équipe RDF', $feed->items[0]->author);
        $this->assertSame('2026-10-28 16:00:00', $feed->items[0]->publishedAt->format('Y-m-d H:i:s'));

        // dc:date 12:00 +01:00 = 11:00 UTC.
        $this->assertSame('2026-10-27 11:00:00', $feed->items[1]->publishedAt->format('Y-m-d H:i:s'));
    }

    public function test_atom_feed(): void
    {
        $feed = $this->parser->parse($this->fixture('atom_valid.xml'));

        $this->assertSame(FeedFormat::Atom, $feed->format);
        $this->assertSame('Flux Atom', $feed->title);
        // rel="alternate" choisi pour site_url, pas rel="self".
        $this->assertSame('https://www.exemple.fr/', $feed->siteUrl);
        $this->assertSame('Canal Atom complet', $feed->description);
        $this->assertSame('fr', $feed->language);
        $this->assertCount(2, $feed->items);

        $first = $feed->items[0];
        // <id> Atom : identifiant permanent → exploitable.
        $this->assertSame('tag:exemple.fr,2026:e1', $first->guid);
        $this->assertTrue($first->guidIsPermalink);
        $this->assertSame('https://www.exemple.fr/e1', $first->url);
        $this->assertSame('Bob Dupont', $first->author);
        $this->assertSame("Résumé de l'entrée Atom.", $first->summary);
        $this->assertStringContainsString('<em>HTML</em>', (string) $first->content);
        $this->assertSame('2026-10-30 08:00:00', $first->publishedAt->format('Y-m-d H:i:s'));
        // updated 09:15 +01:00 = 08:15 UTC.
        $this->assertSame('2026-10-31 08:15:00', $first->updatedAt->format('Y-m-d H:i:s'));

        $second = $feed->items[1];
        $this->assertSame('tag:exemple.fr,2026:e2', $second->guid);
        $this->assertNull($second->publishedAt); // absent → null, pas de crash
        $this->assertNotNull($second->updatedAt);
    }

    public function test_rss20_enclosure_never_becomes_article_url(): void
    {
        $feed = $this->parser->parse($this->fixture('rss20_enclosures.xml'));
        $items = $feed->items;
        $this->assertCount(3, $items);

        // link + enclosure : le lien de l'article prime.
        $this->assertSame('https://www.exemple.fr/a1', $items[0]->url);

        // Enclosure seule (guid non permalien) : aucune URL d'article —
        // get_permalink() aurait retourné l'enclosure.
        $this->assertNull($items[1]->url);
        $this->assertSame('enc-2', $items[1]->guid);
        $this->assertNotEmpty(
            array_filter($feed->warnings, fn (string $w) => str_contains($w, '#1') && str_contains($w, 'URL')),
        );

        // guid permalien + enclosure, sans <link> : le guid fait office
        // d'URL (spec RSS), pas l'enclosure.
        $this->assertSame('https://www.exemple.fr/a3', $items[2]->url);
    }

    public function test_atom_enclosure_rel_is_not_article_url(): void
    {
        $items = $this->parser->parse($this->fixture('atom_enclosures.xml'))->items;
        $this->assertCount(2, $items);

        // rel="alternate" choisi ; rel="enclosure" ignoré pour l'URL.
        $this->assertSame('https://www.exemple.fr/e1', $items[0]->url);

        // Entrée sans lien alternate : aucune URL d'article.
        $this->assertNull($items[1]->url);
        $this->assertSame('tag:exemple.fr,2026:enc-e2', $items[1]->guid);
    }

    public function test_empty_channel_is_valid_feed_with_zero_items(): void
    {
        $feed = $this->parser->parse($this->fixture('rss20_empty.xml'));

        $this->assertSame(FeedFormat::Rss, $feed->format);
        $this->assertSame('Flux vide', $feed->title);
        $this->assertSame([], $feed->items);
    }

    #[DataProvider('invalidDocuments')]
    public function test_unusable_documents_throw_parse_exception(string $xml): void
    {
        $this->expectException(FeedParseException::class);
        $this->parser->parse($xml);
    }

    /** @return array<string, array{0: string}> */
    public static function invalidDocuments(): array
    {
        return [
            'chaîne vide' => [''],
            'espaces seuls' => ["   \n\t  "],
            'texte brut' => ['ceci n\'est pas du XML'],
        ];
    }

    public function test_malformed_xml_throws_parse_exception(): void
    {
        $this->expectException(FeedParseException::class);
        $this->parser->parse($this->fixture('invalid_malformed.xml'));
    }

    public function test_valid_xml_that_is_not_a_feed_throws(): void
    {
        $this->expectException(FeedParseException::class);
        $this->parser->parse($this->fixture('invalid_not_feed.xml'));
    }

    public function test_parser_never_fetches_urls_from_content(): void
    {
        // Le XML peut contenir des URL quelconques : le parseur ne les
        // résout ni ne les contacte jamais — il ne reçoit que la chaîne.
        $xml = '<?xml version="1.0"?><rss version="2.0"><channel>'
            .'<title>t</title><link>https://10.0.0.1/interne</link>'
            .'<item><title>i</title><link>http://169.254.169.254/meta</link>'
            .'<guid isPermaLink="false">x</guid></item></channel></rss>';

        $feed = $this->parser->parse($xml);

        $this->assertSame('https://10.0.0.1/interne', $feed->siteUrl);
        $this->assertSame('http://169.254.169.254/meta', $feed->items[0]->url);
    }
}
