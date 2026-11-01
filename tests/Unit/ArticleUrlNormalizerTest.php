<?php

namespace Tests\Unit;

use App\Services\Feed\ArticleUrlNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArticleUrlNormalizer::class)]
class ArticleUrlNormalizerTest extends TestCase
{
    private ArticleUrlNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new ArticleUrlNormalizer;
    }

    #[DataProvider('normalizedUrls')]
    public function test_normalization_rules(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, $this->normalizer->normalize($input));
    }

    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function normalizedUrls(): array
    {
        return [
            'schéma et hôte minuscules' => ['HTTP://EXEMPLE.FR/Article', 'http://exemple.fr/Article'],
            'www initial supprimé' => ['https://www.exemple.fr/a', 'https://exemple.fr/a'],
            'www non initial conservé' => ['https://www2.exemple.fr/a', 'https://www2.exemple.fr/a'],
            'port http 80 retiré' => ['http://exemple.fr:80/a', 'http://exemple.fr/a'],
            'port https 443 retiré' => ['https://exemple.fr:443/a', 'https://exemple.fr/a'],
            'port non standard conservé' => ['http://exemple.fr:8080/a', 'http://exemple.fr:8080/a'],
            'port http 443 conservé' => ['http://exemple.fr:443/a', 'http://exemple.fr:443/a'],
            'fragment supprimé' => ['https://exemple.fr/a#section', 'https://exemple.fr/a'],
            'query conservée intégralement' => ['https://exemple.fr/a?id=1&page=2', 'https://exemple.fr/a?id=1&page=2'],
            'ordre des paramètres conservé' => ['https://exemple.fr/a?z=1&a=2', 'https://exemple.fr/a?z=1&a=2'],
            'chemin conservé' => ['https://exemple.fr/a/b/c.html', 'https://exemple.fr/a/b/c.html'],
            'utm_* retirés' => ['https://exemple.fr/a?utm_source=x&id=1&utm_medium=y', 'https://exemple.fr/a?id=1'],
            'fbclid retiré' => ['https://exemple.fr/a?id=1&fbclid=abc', 'https://exemple.fr/a?id=1'],
            'gclid retiré' => ['https://exemple.fr/a?gclid=abc&id=1', 'https://exemple.fr/a?id=1'],
            'mc_* retirés' => ['https://exemple.fr/a?mc_cid=x&mc_eid=y&id=1', 'https://exemple.fr/a?id=1'],
            'tracking param sans valeur' => ['https://exemple.fr/a?utm_source&id=1', 'https://exemple.fr/a?id=1'],
            'param ressemblant à tracking conservé' => ['https://exemple.fr/a?utmation=1&id=2', 'https://exemple.fr/a?utmation=1&id=2'],
            'tracking seul : plus de query' => ['https://exemple.fr/a?utm_source=x', 'https://exemple.fr/a'],
            'query vide' => ['https://exemple.fr/a?', 'https://exemple.fr/a'],
            'encodage conservé tel quel' => ['https://exemple.fr/a%2Fb?q=%3A', 'https://exemple.fr/a%2Fb?q=%3A'],
            'idn conservé tel quel' => ['https://bücher.exemple.fr/a', 'https://bücher.exemple.fr/a'],
            'userinfo conservée' => ['https://user:pass@exemple.fr/a', 'https://user:pass@exemple.fr/a'],
            'ipv6 littérale' => ['https://[2001:4860:4860::8888]/a', 'https://[2001:4860:4860::8888]/a'],
            'espaces bordures' => ['  https://exemple.fr/a  ', 'https://exemple.fr/a'],
            'nulle' => [null, null],
            'vide' => ['', null],
            'espaces seuls' => ['   ', null],
            'pas une url' => ['ceci nest pas une url', null],
            'relative' => ['/chemin/seul', null],
            'sans schéma' => ['exemple.fr/a', null],
            'sans hôte' => ['http:///chemin', null],
        ];
    }

    public function test_deterministic(): void
    {
        $url = 'HTTPS://WWW.Exemple.FR:443/A?utm_source=x&b=1#frag';
        $this->assertSame(
            $this->normalizer->normalize($url),
            $this->normalizer->normalize($url),
        );
    }
}
