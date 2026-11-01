<?php

namespace Tests\Unit;

use App\Services\Feed\Exceptions\FeedUrlException;
use App\Services\Feed\FeedUrlValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(FeedUrlValidator::class)]
class FeedUrlValidatorTest extends TestCase
{
    private FeedUrlValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new FeedUrlValidator;
    }

    #[DataProvider('acceptedUrls')]
    public function test_accepted_urls(string $url, string $scheme, string $host, int $port): void
    {
        $v = $this->validator->validate($url);

        $this->assertSame($scheme, $v->scheme);
        $this->assertSame($host, $v->host);
        $this->assertSame($port, $v->port);
        $this->assertStringStartsWith("{$scheme}://", $v->uri);
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: int}> */
    public static function acceptedUrls(): array
    {
        return [
            'http simple' => ['http://flux.exemple.com/feed', 'http', 'flux.exemple.com', 80],
            'https simple' => ['https://flux.exemple.com/feed', 'https', 'flux.exemple.com', 443],
            'port 80 explicite' => ['http://a.fr:80/x', 'http', 'a.fr', 80],
            'port 443 explicite' => ['https://a.fr:443/x', 'https', 'a.fr', 443],
            'ipv4 littérale' => ['http://93.184.216.34/feed', 'http', '93.184.216.34', 80],
            'ipv6 littérale' => ['https://[2606:4700:4700::1111]/x', 'https', '2606:4700:4700::1111', 443],
            'query conservée' => ['https://a.fr/f?x=1&y=2', 'https', 'a.fr', 443],
            // Domaines légitimes composés de caractères hexadécimaux :
            // inet_aton ne peut pas les réinterpréter — acceptés.
            'domaine hex cafe.de' => ['https://cafe.de/feed', 'https', 'cafe.de', 443],
            'domaine hex b.de' => ['https://b.de/feed', 'https', 'b.de', 443],
            'domaine hex deadbeef.tld' => ['https://deadbeef.tld/rss', 'https', 'deadbeef.tld', 443],
            'domaine hex dead.beef' => ['https://dead.beef/feed', 'https', 'dead.beef', 443],
        ];
    }

    public function test_idn_host_is_converted_to_punycode(): void
    {
        $v = $this->validator->validate('https://bücher.exemple.fr/feed');

        $this->assertStringStartsWith('xn--', $v->host);
        $this->assertStringContainsString($v->host, $v->uri);
    }

    public function test_fragment_ne_rentre_pas_dans_l_uri(): void
    {
        $v = $this->validator->validate('https://a.fr/feed#section');

        $this->assertSame('https://a.fr/feed', $v->uri);
    }

    #[DataProvider('rejectedUrls')]
    public function test_rejected_urls(string $url): void
    {
        $this->expectException(FeedUrlException::class);
        $this->validator->validate($url);
    }

    /** @return array<string, array{0: string}> */
    public static function rejectedUrls(): array
    {
        return [
            'vide' => [''],
            'espaces' => ['   '],
            'pas une url' => ['pas une url'],
            'ftp' => ['ftp://a.fr/feed'],
            'file' => ['file:///etc/passwd'],
            'gopher' => ['gopher://a.fr/'],
            'javascript' => ['javascript:alert(1)'],
            'data' => ['data:text/xml,<x/>'],
            'sans schéma' => ['a.fr/feed'],
            'schéma relatif' => ['//a.fr/feed'],
            'port 8080' => ['http://a.fr:8080/feed'],
            'port 8443' => ['https://a.fr:8443/feed'],
            'port 22' => ['http://a.fr:22/feed'],
            'userinfo' => ['https://user:pass@a.fr/feed'],
            'userinfo sans pass' => ['https://user@a.fr/feed'],
            'hôte absent' => ['http:///feed'],
            'ipv4 décimal' => ['http://2130706433/'],
            'ipv4 hexadécimal' => ['http://0x7f000001/'],
            'ipv4 octal' => ['http://0177.0.0.1/'],
            'ipv4 décimal pointé court' => ['http://127.1/'],
            'ipv4 3 parties' => ['http://10.0.1/'],
            'ipv4 mixte hexa-décimal' => ['http://0x7f.1/'],
            'ipv4 hexadécimal pointé' => ['http://0x7f.0x00.0x00.0x01/'],
            'ipv4 numérique 5 parties' => ['http://1.2.3.4.5/'],
            'caractère espace' => ['http://a .fr/feed'],
            'hôte mono-label hexadécimal' => ['http://deadbeef/'],
            'hôte mono-label localhost' => ['http://localhost/feed'],
            'hôte mono-label interne' => ['http://intranet/feed'],
        ];
    }
}
