<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Feed\Exceptions\FeedParseException;
use App\Services\Feed\SimplePieFeedParser;
use App\Services\Feed\XmlFeedRepair;
use Tests\TestCase;

/** M12.17 — récupération conservative des flux XML défectueux. */
class XmlFeedRepairTest extends TestCase
{
    private function rss(string $extra = '', string $desc = 'd'): string
    {
        return '<?xml version="1.0"?><rss version="2.0"><channel><title>T</title>'
            .'<item><title>i</title><link>https://ex.fr/1</link><guid>g1</guid>'
            .'<description>'.$desc.'</description>'.$extra.'</item></channel></rss>';
    }

    private SimplePieFeedParser $p;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p = new SimplePieFeedParser;
    }

    public function test_flux_rss_valide(): void
    {
        $f = $this->p->parse($this->rss());
        $this->assertCount(1, $f->items);
        $this->assertFalse($this->p->lastRecovered());
    }

    public function test_flux_atom_valide(): void
    {
        $x = '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom">'
            .'<title>T</title><entry><title>i</title>'
            .'<link href="https://ex.fr/a"/></entry></feed>';
        $f = $this->p->parse($x);
        $this->assertCount(1, $f->items);
    }

    public function test_utf8_invalide_repare(): void
    {
        // repair() retire les octets illégaux (0xFF) sans toucher au
        // contenu valide ; SimplePie tolère parfois l'original —
        // la réparation n'est tentée qu'en cas d'échec effectif.
        $bad = "a\xFFb\x80c";
        $fixed = XmlFeedRepair::repair($bad);
        // Octets illégaux remplacés par '?' — jamais d'invention de
        // contenu, toujours un document XML-valide.
        $this->assertSame('a?b?c', $fixed);
        // Document déjà sain : repair() renvoie null (rien à faire).
        $this->assertNull(XmlFeedRepair::repair($this->rss()));
    }

    public function test_caractere_controle_interdit_recupere(): void
    {
        // Caractère XML 1.0 interdit dans un attribut → casse le parsing.
        $xml = str_replace('<title>i</title>', "<title a='\x1F'>i</title>", $this->rss());
        $f = $this->p->parse($xml);
        $this->assertCount(1, $f->items);
        $this->assertTrue($this->p->lastRecovered());
    }

    public function test_balise_malformee_rejetee(): void
    {
        // &amp; non échappé + balise non fermée : hors périmètre de réparation.
        $this->expectException(FeedParseException::class);
        $this->p->parse($this->rss('', 'A & B <unclosed>'));
    }

    public function test_html_rejete(): void
    {
        $this->expectException(FeedParseException::class);
        $this->p->parse('<!DOCTYPE html><html><body>404</body></html>');
    }

    public function test_vide_rejete(): void
    {
        $this->expectException(FeedParseException::class);
        $this->p->parse('');
    }

    public function test_recuperation_impossible_erreur_initiale(): void
    {
        // Doc non-XML du tout : message d'origine conservé.
        try {
            $this->p->parse('{"not":"xml"}');
            $this->fail('attendu FeedParseException');
        } catch (FeedParseException $e) {
            $this->assertStringContainsString('non exploitable', $e->getMessage());
        }
    }
}
