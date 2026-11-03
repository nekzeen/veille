<?php

namespace Tests\Unit;

use App\Services\Articles\HtmlContentSanitizer;
use App\Services\Articles\ImageUrlPolicy;
use App\Services\Feed\IpResolverInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HtmlContentSanitizer::class)]
class HtmlContentSanitizerTest extends TestCase
{
    /** Répertoire de cache valide pour les tests (storage réel). */
    private static function realCacheDir(): string
    {
        return dirname(__DIR__, 2).'/storage/app/htmlpurifier';
    }

    private static function sanitizer(?ImageUrlPolicy $policy = null, ?string $cacheDir = null): HtmlContentSanitizer
    {
        return new HtmlContentSanitizer(
            imageUrls: $policy ?? new ImageUrlPolicy(resolver: new PublicDnsStub),
            cacheDir: $cacheDir ?? self::realCacheDir(),
        );
    }

    public function test_supprime_script_et_attributs_dangereux(): void
    {
        $out = self::sanitizer()->sanitize(
            '<p onclick="x()">ok</p><script>alert(1)</script><iframe src="https://e.com"></iframe>',
        );
        $this->assertStringContainsString('<p>ok</p>', $out);
        $this->assertStringNotContainsString('script', $out);
        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringNotContainsString('iframe', $out);
    }

    public function test_conserve_balises_et_attributs_autorises(): void
    {
        $out = self::sanitizer()->sanitize(
            '<h2>T</h2><p><strong>a</strong> <em>b</em> <code>c</code></p>'
            .'<blockquote>q</blockquote><ul><li>l</li></ul><pre>p</pre>'
            .'<table><thead><tr><th>h</th></tr></thead><tbody><tr><td>d</td></tr></tbody></table><hr>'
            .'<a href="https://ex.com/x" title="t">lien</a>',
        );
        foreach (['<h2>', '<strong>', '<em>', '<code>', '<blockquote>', '<ul>', '<li>', '<pre>', '<table>', '<td>', '<hr', 'href="https://ex.com/x"', 'title="t"'] as $frag) {
            $this->assertStringContainsString($frag, $out, "fragment attendu : {$frag}");
        }
    }

    public function test_supprime_balises_et_attributs_non_autorises(): void
    {
        $out = self::sanitizer()->sanitize(
            '<div class="x">t</div><span style="color:red">s</span><form><input></form><p id="p1">ok</p>',
        );
        $this->assertStringNotContainsString('<div', $out);
        $this->assertStringNotContainsString('<span', $out);
        $this->assertStringNotContainsString('style', $out);
        $this->assertStringNotContainsString('<form', $out);
        $this->assertStringNotContainsString('<input', $out);
        $this->assertStringNotContainsString('id="p1"', $out);
    }

    #[DataProvider('badSchemes')]
    public function test_refuse_schemes_uri_non_autorises(string $html): void
    {
        $out = self::sanitizer()->sanitize($html);
        $this->assertStringNotContainsString('href', $out);
        $this->assertStringNotContainsString('src', $out);
    }

    /** @return array<string, array{0: string}> */
    public static function badSchemes(): array
    {
        return [
            'javascript href' => ['<a href="javascript:alert(1)">x</a>'],
            'data img' => ['<img src="data:image/png;base64,AA">'],
            'file img' => ['<img src="file:///etc/passwd">'],
            'vbscript href' => ['<a href="vbscript:x">x</a>'],
        ];
    }

    public function test_conserve_liens_http_https_et_ajoute_nofollow(): void
    {
        $out = self::sanitizer()->sanitize(
            '<a href="http://a.fr">h</a> <a href="https://b.fr">s</a>',
        );
        $this->assertStringContainsString('href="http://a.fr"', $out);
        $this->assertStringContainsString('href="https://b.fr"', $out);
        $this->assertStringContainsString('rel="nofollow"', $out);
        $this->assertStringNotContainsString('target=', $out);
    }

    public function test_definitions_html5_figure_figcaption_time(): void
    {
        $out = self::sanitizer()->sanitize(
            '<figure><img src="https://8.8.8.8/i.png" alt="a"><figcaption>Légende <em>n</em></figcaption></figure>'
            .'<time datetime="2026-01-01">d</time>',
        );
        $this->assertStringContainsString('<figure>', $out);
        $this->assertStringContainsString('<figcaption>', $out);
        $this->assertStringContainsString('datetime="2026-01-01"', $out);
    }

    public function test_image_valide_recoit_referrerpolicy_et_loading(): void
    {
        $out = self::sanitizer()->sanitize('<img src="https://8.8.8.8/i.png" alt="a">');
        $this->assertStringContainsString('src="https://8.8.8.8/i.png"', $out);
        $this->assertStringContainsString('referrerpolicy="no-referrer"', $out);
        $this->assertStringContainsString('loading="lazy"', $out);
    }

    public function test_image_nom_public_dns_stub(): void
    {
        $policy = new ImageUrlPolicy(resolver: new class implements IpResolverInterface
        {
            public function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
        $out = self::sanitizer($policy)->sanitize('<img src="https://cdn.example.com/i.png">');
        $this->assertStringContainsString('src="https://cdn.example.com/i.png"', $out);
    }

    #[DataProvider('badImageSrc')]
    public function test_image_invalide_supprimee_entierement(string $src): void
    {
        $out = self::sanitizer()->sanitize("<p>t</p><img src=\"{$src}\" alt=\"a\"><p>u</p>");
        $this->assertStringNotContainsString('<img', $out);
        $this->assertStringContainsString('<p>u</p>', $out);
    }

    /** @return array<string, array{0: string}> */
    public static function badImageSrc(): array
    {
        return [
            'ipv4 privée' => ['http://10.0.0.1/i.png'],
            'loopback' => ['http://127.0.0.1/i.png'],
            'localhost' => ['http://localhost/i.png'],
            'credentials + ip privée' => ['http://u:p@127.0.0.1/i.png'],
            'numérique ambigu' => ['http://2130706433/i.png'],
        ];
    }

    public function test_entrees_vides_null_et_interdites_seules(): void
    {
        $s = self::sanitizer();
        $this->assertSame('', $s->sanitize(null));
        $this->assertSame('', $s->sanitize(''));
        $this->assertSame('', $s->sanitize('   '));
        $this->assertSame('', $s->sanitize('<script>alert(1)</script><iframe></iframe>'));
    }

    public function test_utf8_accents_entites_preserves(): void
    {
        $out = self::sanitizer()->sanitize('<p>Éléments é à ü « ç » &mdash; fin</p>');
        $this->assertStringContainsString('Éléments é à ü « ç »', $out);
        $this->assertStringNotContainsString('Ã©', $out);
    }

    public function test_compteurs_reinitialises_entre_deux_documents(): void
    {
        $s = self::sanitizer();
        $doc1 = str_repeat('<img src="http://8.8.8.8/i.png">', 51);
        $out1 = $s->sanitize($doc1);
        $this->assertSame(50, substr_count($out1, '<img'), '51e image supprimée (plafond doc)');

        // Deuxième document : le plafond repart à zéro.
        $out2 = $s->sanitize('<img src="http://8.8.8.8/i.png">');
        $this->assertStringContainsString('<img', $out2);
    }

    public function test_cache_repertoire_absent_ou_non_inscriptible(): void
    {
        foreach (['/tmp/hp-cache-absent-xyz', $ro = '/tmp/hp-cache-ro'] as $dir) {
            if ($dir === $ro && ! is_dir($dir)) {
                mkdir($dir, 0555, true);
            }
            $out = self::sanitizer(cacheDir: $dir)->sanitize('<p>ok <strong>g</strong></p>');
            $this->assertStringContainsString('<strong>g</strong>', $out, "cache indisponible ({$dir}) : purify doit fonctionner en repli");
        }
        chmod('/tmp/hp-cache-ro', 0755);
    }

    public function test_cache_repertoire_valide_cree_les_sous_dossiers(): void
    {
        self::sanitizer()->sanitize('<p>cache</p>');
        $this->assertDirectoryExists(self::realCacheDir().'/HTML');
    }
}

/** Stub DNS « tout public » — aucune dépendance réseau. */
final class PublicDnsStub implements IpResolverInterface
{
    public function resolve(string $host): array
    {
        return ['93.184.216.34'];
    }
}
