<?php

namespace Tests\Unit;

use App\Services\Feed\ArticleLinkResolver;
use App\Services\Feed\Html\HtmlExtractor;
use App\Services\Feed\JsonFeedParser;
use Tests\TestCase;

class ArticleLinkResolverTest extends TestCase
{
    public function test_url_google_de_la_demande_resolue(): void
    {
        $this->assertSame(
            'https://www.lesbonsfreelances.com/freelance/valeriep7',
            ArticleLinkResolver::resolve(
                'https://www.google.com/url?rct=j&sa=t&url=https://www.lesbonsfreelances.com/freelance/valeriep7&ct=ga&cd=abc&usg=xyz',
            ),
        );
    }

    public function test_entite_html_amp_decodee_une_fois(): void
    {
        $this->assertSame(
            'https://ex.fr/a?x=1&y=2',
            ArticleLinkResolver::resolve('https://ex.fr/a?x=1&amp;y=2'),
        );
        // `&amp;amp;` ne doit pas être décodé deux fois.
        $this->assertSame(
            'https://ex.fr/a?x=1&amp;y=2',
            ArticleLinkResolver::resolve('https://ex.fr/a?x=1&amp;amp;y=2'),
        );
    }

    public function test_destination_encodee_et_multi_parametres(): void
    {
        $redirect = 'https://www.google.com/url?q='.rawurlencode('https://ex.fr/p?a=1&b=2');
        $this->assertSame('https://ex.fr/p?a=1&b=2', ArticleLinkResolver::resolve($redirect));
    }

    public function test_parametre_q_seul_et_url_seul(): void
    {
        $this->assertSame('https://ex.fr/x', ArticleLinkResolver::resolve('https://www.google.com/url?q=https%3A%2F%2Fex.fr%2Fx'));
        $this->assertSame('https://ex.fr/y', ArticleLinkResolver::resolve('https://www.google.com/url?url=https%3A%2F%2Fex.fr%2Fy'));
    }

    public function test_sans_destination_original_conserve(): void
    {
        $u = 'https://www.google.com/url?sa=t&rct=j';
        $this->assertSame($u, ArticleLinkResolver::resolve($u));
    }

    public function test_ambiguite_url_plus_q_conserve(): void
    {
        $u = 'https://www.google.com/url?url=https%3A%2F%2Fa.fr&q=https%3A%2F%2Fb.fr';
        $this->assertSame($u, ArticleLinkResolver::resolve($u));
    }

    public function test_destination_malformee_ou_protocole_interdit_conserves(): void
    {
        $u = 'https://www.google.com/url?url=javascript%3Aalert(1)';
        $this->assertSame($u, ArticleLinkResolver::resolve($u));
        $u2 = 'https://www.google.com/url?url=data%3Atext%2Fhtml%2Cx';
        $this->assertSame($u2, ArticleLinkResolver::resolve($u2));
    }

    public function test_url_google_ordinaire_inchangee(): void
    {
        $u = 'https://www.google.com/search?q=veille';
        $this->assertSame($u, ArticleLinkResolver::resolve($u));
    }

    public function test_url_classique_inchangee_et_vides(): void
    {
        $u = 'https://ex.fr/article?id=1';
        $this->assertSame($u, ArticleLinkResolver::resolve($u));
        $this->assertNull(ArticleLinkResolver::resolve(null));
        $this->assertNull(ArticleLinkResolver::resolve('   '));
    }

    public function test_applique_sur_json_feed_et_html(): void
    {
        $redir = 'https://www.google.com/url?url='.rawurlencode('https://ex.fr/art');

        $json = new JsonFeedParser;
        $feed = $json->parse((string) json_encode([
            'version' => 'https://jsonfeed.org/version/1',
            'items' => [['id' => '1', 'url' => $redir, 'title' => 'A']],
        ]));
        $this->assertSame('https://ex.fr/art', $feed->items[0]->url);

        $html = new HtmlExtractor;
        $doc = '<html><body><ul>'
            .'<li><a href="'.htmlspecialchars($redir).'">Article un</a></li>'
            .'<li><a href="https://ex.fr/b">Article deux</a></li>'
            .'<li><a href="https://ex.fr/c">Article trois</a></li>'
            .'</ul></body></html>';
        $parsed = $html->extract($doc, 'https://ex.fr/news');
        $this->assertSame('https://ex.fr/art', $parsed->items[0]->url);
    }
}
