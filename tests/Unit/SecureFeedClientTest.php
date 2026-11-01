<?php

namespace Tests\Unit;

use App\Services\Feed\Exceptions\FeedDnsException;
use App\Services\Feed\Exceptions\FeedHttpException;
use App\Services\Feed\Exceptions\FeedNetworkException;
use App\Services\Feed\Exceptions\FeedRedirectException;
use App\Services\Feed\Exceptions\FeedTimeoutException;
use App\Services\Feed\Exceptions\FeedTlsException;
use App\Services\Feed\Exceptions\FeedTooLargeException;
use App\Services\Feed\Exceptions\FeedUrlException;
use App\Services\Feed\IpResolverInterface;
use App\Services\Feed\SecureFeedClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

#[CoversClass(SecureFeedClient::class)]
class SecureFeedClientTest extends TestCase
{
    private const PUBLIC_IP = '93.184.216.34';

    private const PUBLIC_IP2 = '93.184.216.35';

    private const MAX = 5_242_880;

    /** @var list<array{request: RequestInterface, options: array}> */
    private array $seen = [];

    private FakeIpResolver $fakeResolver;

    private function client(array $map, array $queue): SecureFeedClient
    {
        $mock = new MockHandler($queue);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::tap(function (RequestInterface $request, array $options): void {
            $this->seen[] = ['request' => $request, 'options' => $options];
        }));
        $this->fakeResolver = new FakeIpResolver($map);

        return new SecureFeedClient(
            $this->fakeResolver,
            new Client(['handler' => $stack]),
        );
    }

    private function connError(int $errno, string $msg): ConnectException
    {
        return new ConnectException(
            "cURL error {$errno}: {$msg}",
            new Request('GET', 'http://x'),
            null,
            ['errno' => $errno, 'error' => $msg],
        );
    }

    // ---------- Cas nominal ----------

    public function test_fetch_200_returns_body_and_metadata(): void
    {
        $xml = '<?xml version="1.0"?><rss version="2.0"><channel/></rss>';
        $client = $this->client(
            ['flux.exemple.com' => [self::PUBLIC_IP]],
            [new Response(200, ['ETag' => '"abc"', 'Last-Modified' => 'Wed, 01 Oct 2026 00:00:00 GMT'], $xml)],
        );

        $r = $client->fetch('https://flux.exemple.com/feed.xml');

        $this->assertSame(200, $r->statusCode);
        $this->assertSame($xml, $r->body);
        $this->assertSame('https://flux.exemple.com/feed.xml', $r->url);
        $this->assertSame('https://flux.exemple.com/feed.xml', $r->effectiveUrl);
        $this->assertSame('"abc"', $r->etag);
        $this->assertFalse($r->notModified);
        $this->assertSame(strlen($xml), $r->bytesRead);
        $this->assertSame(0, $r->redirects);
    }

    public function test_headers_and_security_options_sent(): void
    {
        $client = $this->client(
            ['flux.exemple.com' => [self::PUBLIC_IP]],
            [new Response(200, [], '<rss/>')],
        );

        $client->fetch('https://flux.exemple.com/feed.xml', '"tag-1"', 'Wed, 01 Oct 2026 00:00:00 GMT');

        $this->assertCount(1, $this->seen);
        [$req, $opts] = [$this->seen[0]['request'], $this->seen[0]['options']];

        // En-têtes imposés.
        $this->assertSame('VeilleBot/1.0', $req->getHeaderLine('User-Agent'));
        $this->assertSame('identity', $req->getHeaderLine('Accept-Encoding'));
        $this->assertSame('"tag-1"', $req->getHeaderLine('If-None-Match'));
        $this->assertSame('flux.exemple.com', $req->getHeaderLine('Host'));
        $this->assertSame('flux.exemple.com', $req->getUri()->getHost());

        // Épinglage et protections transport.
        $this->assertSame(
            ['flux.exemple.com:443:'.self::PUBLIC_IP],
            $opts['curl'][CURLOPT_RESOLVE],
        );
        $this->assertSame('', $opts['curl'][CURLOPT_PROXY]);
        $this->assertSame(5, $opts['curl'][CURLOPT_CONNECTTIMEOUT]);
        $this->assertSame(15, $opts['curl'][CURLOPT_TIMEOUT]);
        $this->assertSame(8192, $opts['curl'][CURLOPT_LOW_SPEED_LIMIT]);
        $this->assertSame(10, $opts['curl'][CURLOPT_LOW_SPEED_TIME]);
        $this->assertTrue($opts['curl'][CURLOPT_SSL_VERIFYPEER]);
        $this->assertSame(2, $opts['curl'][CURLOPT_SSL_VERIFYHOST]);
        $this->assertSame(CURL_SSLVERSION_TLSv1_2, $opts['curl'][CURLOPT_SSLVERSION]);
        $this->assertFalse($opts['allow_redirects']);
        $this->assertFalse($opts['decode_content']);
        // La vérification TLS n'est jamais désactivée (Guzzle pose la
        // valeur par défaut : chemin CA ou true — jamais false).
        $this->assertNotSame(false, $opts['verify'] ?? true);
    }

    public function test_304_is_not_modified(): void
    {
        $client = $this->client(
            ['a.fr' => [self::PUBLIC_IP]],
            [new Response(304, ['ETag' => '"idem"'])],
        );

        $r = $client->fetch('https://a.fr/f', '"idem"');

        $this->assertTrue($r->notModified);
        $this->assertSame(304, $r->statusCode);
        $this->assertSame('', $r->body);
        $this->assertSame(0, $r->bytesRead);
    }

    // ---------- URL ----------

    public function test_rejected_scheme_fails_before_any_request(): void
    {
        $client = $this->client(['a.fr' => [self::PUBLIC_IP]], []);

        $this->expectException(FeedUrlException::class);
        try {
            $client->fetch('ftp://a.fr/feed');
        } finally {
            $this->assertSame([], $this->seen); // aucune requête émise
        }
    }

    public function test_rejected_port_fails_before_any_request(): void
    {
        $client = $this->client(['a.fr' => [self::PUBLIC_IP]], []);

        $this->expectException(FeedUrlException::class);
        try {
            $client->fetch('http://a.fr:8080/feed');
        } finally {
            $this->assertSame([], $this->seen);
        }
    }

    // ---------- DNS / politique IP ----------

    public function test_private_literal_ip_is_rejected_before_request(): void
    {
        $client = $this->client([], []);

        $this->expectException(FeedDnsException::class);
        try {
            $client->fetch('http://169.254.169.254/latest/meta-data');
        } finally {
            $this->assertSame([], $this->fakeResolver->calls); // littéral : pas de DNS
            $this->assertSame([], $this->seen);
        }
    }

    public function test_dns_without_answer_is_rejected(): void
    {
        $client = $this->client(['mort.exemple' => []], []);

        $this->expectException(FeedDnsException::class);
        $client->fetch('https://mort.exemple/feed');
    }

    public function test_mixed_dns_answer_is_fully_rejected(): void
    {
        // P3 : une seule IP non globale dans la réponse → rejet complet.
        $client = $this->client(
            ['mix.exemple' => [self::PUBLIC_IP, '10.0.0.9']],
            [],
        );

        $this->expectException(FeedDnsException::class);
        try {
            $client->fetch('https://mix.exemple/feed');
        } finally {
            $this->assertSame([], $this->seen);
        }
    }

    public function test_ipv6_ip_is_pinned_in_resolve_entry(): void
    {
        $v6 = '2606:4700:4700::1111';
        $client = $this->client(
            ['v6.exemple' => [$v6]],
            [new Response(200, [], '<rss/>')],
        );

        $client->fetch('https://v6.exemple/feed');

        $this->assertSame(
            ["v6.exemple:443:{$v6}"],
            $this->seen[0]['options']['curl'][CURLOPT_RESOLVE],
        );
    }

    public function test_dns_change_between_calls_is_revalidated(): void
    {
        // Rebinding simulé : le résolveur retourne une IP publique à
        // la première requête, une IP privée à la seconde — chaque
        // résolution repasse par la politique IP.
        $client = $this->client(
            ['a.fr' => [[self::PUBLIC_IP], ['10.0.0.9']]],
            [new Response(200, [], '<rss/>')],
        );

        $r = $client->fetch('https://a.fr/f');
        $this->assertSame(200, $r->statusCode);

        $this->expectException(FeedDnsException::class);
        $client->fetch('https://a.fr/f');
    }

    public function test_multiple_public_ips_failover_on_connect_error(): void
    {
        $client = $this->client(
            ['multi.exemple' => [self::PUBLIC_IP, self::PUBLIC_IP2]],
            [$this->connError(7, 'connection refused'), new Response(200, [], '<rss/>')],
        );

        $r = $client->fetch('http://multi.exemple/f');

        $this->assertSame(200, $r->statusCode);
        $this->assertCount(2, $this->seen);
        $this->assertSame(
            ['multi.exemple:80:'.self::PUBLIC_IP],
            $this->seen[0]['options']['curl'][CURLOPT_RESOLVE],
        );
        $this->assertSame(
            ['multi.exemple:80:'.self::PUBLIC_IP2],
            $this->seen[1]['options']['curl'][CURLOPT_RESOLVE],
        );
    }

    public function test_all_ips_failing_maps_curl_errno(): void
    {
        $client = $this->client(
            ['dead.exemple' => [self::PUBLIC_IP]],
            [$this->connError(7, 'connection refused')],
        );

        $this->expectException(FeedNetworkException::class);
        $client->fetch('http://dead.exemple/f');
    }

    public function test_timeout_errno_maps_to_timeout_exception(): void
    {
        $client = $this->client(
            ['slow.exemple' => [self::PUBLIC_IP]],
            [$this->connError(28, 'operation timed out')],
        );

        $this->expectException(FeedTimeoutException::class);
        $client->fetch('http://slow.exemple/f');
    }

    public function test_tls_errno_maps_to_tls_exception(): void
    {
        $client = $this->client(
            ['badcert.exemple' => [self::PUBLIC_IP]],
            [$this->connError(60, 'SSL certificate problem: self-signed')],
        );

        $this->expectException(FeedTlsException::class);
        $client->fetch('https://badcert.exemple/f');
    }

    // ---------- HTTP ----------

    #[DataProvider('httpErrors')]
    public function test_http_error_status_throws_http_exception(int $status): void
    {
        $client = $this->client(
            ['a.fr' => [self::PUBLIC_IP]],
            [new Response($status, [], 'x')],
        );

        try {
            $client->fetch('https://a.fr/f');
            $this->fail('FeedHttpException attendue');
        } catch (FeedHttpException $e) {
            $this->assertSame($status, $e->statusCode);
        }
    }

    /** @return array<int, array{0: int}> */
    public static function httpErrors(): array
    {
        // Codes non explicitement acceptés (hors 2xx, 304, 3xx gérés) :
        // erreur HTTP, corps jamais exploité.
        return [[300], [305], [306], [404], [410], [429], [500], [503]];
    }

    // ---------- Redirections ----------

    #[DataProvider('redirectCodes')]
    public function test_redirects_are_followed_and_revalidated(int $code): void
    {
        $client = $this->client(
            ['a.fr' => [self::PUBLIC_IP], 'b.fr' => [self::PUBLIC_IP2]],
            [
                new Response($code, ['Location' => 'https://b.fr/feed.xml']),
                new Response(200, [], '<rss/>'),
            ],
        );

        $r = $client->fetch('https://a.fr/feed');

        $this->assertSame(200, $r->statusCode);
        $this->assertSame('https://b.fr/feed.xml', $r->effectiveUrl);
        $this->assertSame(1, $r->redirects);
        // La seconde tentative est épinglée sur l'IP de la NOUVELLE cible.
        $this->assertSame(
            ['b.fr:443:'.self::PUBLIC_IP2],
            $this->seen[1]['options']['curl'][CURLOPT_RESOLVE],
        );
    }

    /** @return array<int, array{0: int}> */
    public static function redirectCodes(): array
    {
        return [[301], [302], [303], [307], [308]];
    }

    public function test_relative_redirect_is_resolved(): void
    {
        $client = $this->client(
            ['a.fr' => [self::PUBLIC_IP]],
            [
                new Response(301, ['Location' => '/new-feed.xml']),
                new Response(200, [], '<rss/>'),
            ],
        );

        $r = $client->fetch('https://a.fr/old-feed.xml');

        $this->assertSame('https://a.fr/new-feed.xml', $r->effectiveUrl);
    }

    public function test_redirect_to_private_ip_is_rejected(): void
    {
        $client = $this->client(
            ['a.fr' => [self::PUBLIC_IP], 'piege.interne' => ['192.168.10.5']],
            [new Response(302, ['Location' => 'https://piege.interne/feed'])],
        );

        $this->expectException(FeedDnsException::class);
        $client->fetch('https://a.fr/f');
    }

    public function test_redirect_to_literal_private_ip_is_rejected(): void
    {
        $client = $this->client(
            ['a.fr' => [self::PUBLIC_IP]],
            [new Response(302, ['Location' => 'http://10.0.0.5/feed'])],
        );

        $this->expectException(FeedDnsException::class);
        $client->fetch('https://a.fr/f');
    }

    public function test_redirect_to_forbidden_port_is_rejected(): void
    {
        $client = $this->client(
            ['a.fr' => [self::PUBLIC_IP]],
            [new Response(302, ['Location' => 'https://a.fr:8443/f'])],
        );

        $this->expectException(FeedUrlException::class);
        $client->fetch('https://a.fr/f');
    }

    public function test_redirect_to_forbidden_scheme_is_rejected(): void
    {
        $client = $this->client(
            ['a.fr' => [self::PUBLIC_IP]],
            [new Response(302, ['Location' => 'file:///etc/passwd'])],
        );

        $this->expectException(FeedUrlException::class);
        $client->fetch('https://a.fr/f');
    }

    public function test_redirect_loop_exceeds_max(): void
    {
        $client = $this->client(
            ['a.fr' => [self::PUBLIC_IP]],
            [
                new Response(301, ['Location' => '/1']),
                new Response(301, ['Location' => '/2']),
                new Response(301, ['Location' => '/3']),
                new Response(301, ['Location' => '/4']),
            ],
        );

        $this->expectException(FeedRedirectException::class);
        $client->fetch('https://a.fr/0');
    }

    public function test_redirect_without_location_is_rejected(): void
    {
        $client = $this->client(
            ['a.fr' => [self::PUBLIC_IP]],
            [new Response(301)],
        );

        $this->expectException(FeedRedirectException::class);
        $client->fetch('https://a.fr/f');
    }

    // ---------- Taille ----------

    public function test_declared_oversize_is_rejected(): void
    {
        $client = $this->client(
            ['a.fr' => [self::PUBLIC_IP]],
            [new Response(200, ['Content-Length' => (string) (self::MAX + 1)], str_repeat('x', 10))],
        );

        $this->expectException(FeedTooLargeException::class);
        $client->fetch('https://a.fr/f');
    }

    public function test_real_oversize_without_content_length_is_rejected(): void
    {
        $client = $this->client(
            ['a.fr' => [self::PUBLIC_IP]],
            [new Response(200, [], str_repeat('x', self::MAX + 1000))],
        );

        $this->expectException(FeedTooLargeException::class);
        $client->fetch('https://a.fr/f');
    }

    public function test_lying_content_length_is_rejected_on_real_count(): void
    {
        // Annonce mensongère (petite) mais corps réel > plafond.
        $client = $this->client(
            ['a.fr' => [self::PUBLIC_IP]],
            [new Response(200, ['Content-Length' => '100'], str_repeat('x', self::MAX + 10))],
        );

        $this->expectException(FeedTooLargeException::class);
        $client->fetch('https://a.fr/f');
    }

    public function test_body_just_under_limit_is_accepted(): void
    {
        $client = $this->client(
            ['a.fr' => [self::PUBLIC_IP]],
            [new Response(200, [], str_repeat('x', self::MAX - 1))],
        );

        $r = $client->fetch('https://a.fr/f');
        $this->assertSame(self::MAX - 1, $r->bytesRead);
    }

    public function test_empty_body_200_is_accepted(): void
    {
        $client = $this->client(
            ['a.fr' => [self::PUBLIC_IP]],
            [new Response(200)],
        );

        $r = $client->fetch('https://a.fr/f');
        $this->assertSame('', $r->body);
        $this->assertSame(0, $r->bytesRead);
    }

    // ---------- Preuve d'épinglage au niveau cURL ----------

    public function test_curl_resolve_effectively_bypasses_dns(): void
    {
        if (! extension_loaded('curl')) {
            $this->markTestSkipped('ext-curl absente');
        }

        // Mécanisme de plateforme : épingler un domaine inexistant sur
        // 127.0.0.1:54321 → la tentative vise l'IP épinglée, pas le DNS.
        // Errno 6 (resolve host échoué) prouverait que le pin est ignoré ;
        // errno 7 (connexion refusée, rien n'écoute) prouve le pinning.
        $ch = curl_init('http://inexistant.invalid:54321/');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_RESOLVE => ['inexistant.invalid:54321:127.0.0.1'],
            CURLOPT_PROXY => '',
        ]);
        curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        $this->assertNotSame(6, $errno, 'CURLOPT_RESOLVE ignoré : le DNS a été consulté.');
        $this->assertSame(7, $errno, 'Connexion attendue sur l\'IP épinglée (refusée).');
    }

    public function test_curl_resolve_effectively_pins_ipv6(): void
    {
        if (! extension_loaded('curl')) {
            $this->markTestSkipped('ext-curl absente');
        }

        // Même preuve de mécanisme en IPv6 : l'entrée RESOLVE
        // « host:port:::1 » (IPv6 non crochettée, format utilisé par le
        // client) doit envoyer la tentative sur ::1 — errno 6
        // prouverait que le pin est ignoré (DNS consulté).
        $ch = curl_init('http://inexistant-v6.invalid:59987/');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_RESOLVE => ['inexistant-v6.invalid:59987:::1'],
            CURLOPT_PROXY => '',
        ]);
        curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        $this->assertNotSame(6, $errno, 'Pin IPv6 ignoré : le DNS a été consulté.');
        $this->assertSame(7, $errno, 'Connexion attendue sur ::1 épinglé (refusée).');
    }
}

/**
 * Résolveur factice : cartographie hôte → liste d'IP, compte les
 * appels. Une entrée peut aussi être une liste de réponses consommées
 * à chaque appel (simulation de rebinding) — la dernière réponse
 * reste en vigueur une fois la séquence épuisée.
 */
final class FakeIpResolver implements IpResolverInterface
{
    /** @var list<string> */
    public array $calls = [];

    /** @param  array<string, list<string>|list<list<string>>>  $map */
    public function __construct(private array $map = []) {}

    public function resolve(string $host): array
    {
        $this->calls[] = $host;

        $answer = $this->map[$host] ?? [];
        if (isset($answer[0]) && is_array($answer[0])) {
            $next = array_shift($this->map[$host]);
            if ($this->map[$host] === []) {
                $this->map[$host] = [$next];
            }

            return $next;
        }

        return $answer;
    }
}
