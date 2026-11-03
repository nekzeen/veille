<?php

namespace Tests\Unit;

use App\Services\Articles\ImageUrlPolicy;
use App\Services\Feed\IpResolverInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ImageUrlPolicy::class)]
class ImageUrlPolicyTest extends TestCase
{
    /**
     * Résolveur factice : table hôte => IPs, compte les appels.
     * Aucune dépendance réseau — injecté via IpResolverInterface.
     *
     * @param  array<string, list<string>>  $map
     */
    private static function stubResolver(array $map, bool $throws = false): FakeImageHostResolver
    {
        return new FakeImageHostResolver($map, $throws);
    }

    #[DataProvider('acceptedUrls')]
    public function test_url_admise(string $url, array $dnsMap = []): void
    {
        $policy = new ImageUrlPolicy(resolver: self::stubResolver($dnsMap));
        $this->assertTrue($policy->isAllowed($url), "Devrait être admise : {$url}");
    }

    /** @return array<string, array{0: string, 1?: array<string, list<string>>}> */
    public static function acceptedUrls(): array
    {
        return [
            'http IP publique' => ['http://8.8.8.8/i.png'],
            'https IP publique' => ['https://93.184.216.34/i.png'],
            'https IPv6 publique' => ['https://[2606:4700:4700::1111]/i.png'],
            'ipv4-mappée publique' => ['https://[::ffff:8.8.8.8]/i.png'],
            'http nom public' => ['http://cdn.example.com/i.png', ['cdn.example.com' => ['93.184.216.34']]],
            'https nom public' => ['https://cdn.example.com/i.png', ['cdn.example.com' => ['93.184.216.34']]],
            'nom → A + AAAA publiques' => ['https://cdn.example.com/i.png', ['cdn.example.com' => ['93.184.216.34', '2606:4700:4700::1111']]],
            'IDN valide' => ['https://bücher.de/i.png', ['xn--bcher-kva.de' => ['93.184.216.34']]],
            'point final FQDN' => ['https://cdn.example.com./i.png', ['cdn.example.com' => ['93.184.216.34']]],
            'MAJUSCULES normalisées' => ['https://CDN.EXAMPLE.COM/i.png', ['cdn.example.com' => ['93.184.216.34']]],
        ];
    }

    #[DataProvider('rejectedUrls')]
    public function test_url_rejetee(string $url, array $dnsMap = []): void
    {
        $policy = new ImageUrlPolicy(resolver: self::stubResolver($dnsMap));
        $this->assertFalse($policy->isAllowed($url), "Devrait être rejetée : {$url}");
    }

    /** @return array<string, array{0: string, 1?: array<string, list<string>>}> */
    public static function rejectedUrls(): array
    {
        return [
            'schéma javascript' => ['javascript:alert(1)'],
            'schéma data' => ['data:image/png;base64,AAAA'],
            'schéma file' => ['file:///etc/passwd'],
            'schéma ftp' => ['ftp://cdn.example.com/i.png'],
            'sans schéma //' => ['//cdn.example.com/i.png'],
            'chemin relatif' => ['images/i.png'],
            'vide' => [''],
            'identifiants user:pass' => ['https://user:secret@cdn.example.com/i.png'],
            'identifiant user seul' => ['https://user@cdn.example.com/i.png'],
            'hôte déguisé en user' => ['http://evil.com@127.0.0.1/i.png'],
            'IPv4 privée 10/8' => ['http://10.0.0.1/i.png'],
            'IPv4 privée 172.16' => ['http://172.16.5.4/i.png'],
            'IPv4 privée 192.168' => ['http://192.168.1.1/i.png'],
            'IPv4 loopback' => ['http://127.0.0.1/i.png'],
            'IPv4 link-local' => ['http://169.254.1.1/i.png'],
            'IPv4 broadcast' => ['http://255.255.255.255/i.png'],
            'IPv4 réservée 0' => ['http://0.0.0.0/i.png'],
            'IPv4 documentation' => ['http://192.0.2.1/i.png'],
            'IPv6 loopback' => ['http://[::1]/i.png'],
            'IPv6 link-local' => ['http://[fe80::1]/i.png'],
            'IPv6 unique-local' => ['http://[fd00::1]/i.png'],
            'IPv6 multicast' => ['http://[ff02::1]/i.png'],
            'IPv6 documentation' => ['http://[2001:db8::1]/i.png'],
            'ipv4-mappée loopback' => ['http://[::ffff:127.0.0.1]/i.png'],
            'ipv4-mappée privée' => ['http://[::ffff:10.0.0.1]/i.png'],
            'zone-id littérale' => ['http://[fe80::1%eth0]/i.png'],
            'zone-id encodée' => ['http://[fe80::1%25eth0]/i.png'],
            'localhost' => ['http://localhost/i.png'],
            'sous-domaine localhost' => ['http://img.localhost/i.png'],
            'mono-label intranet' => ['http://intranet/i.png'],
            'suffixe .local' => ['http://nas.local/i.png'],
            'suffixe .lan' => ['http://gw.lan/i.png'],
            'suffixe .internal' => ['http://svc.internal/i.png'],
            'suffixe .corp' => ['http://app.corp/i.png'],
            'suffixe .home.arpa' => ['http://box.home.arpa/i.png'],
            'suffixe .test' => ['http://a.test/i.png'],
            'suffixe .example' => ['http://b.example/i.png'],
            'suffixe .invalid' => ['http://c.invalid/i.png'],
            'suffixe .onion' => ['http://d.onion/i.png'],
            'numérique entier' => ['http://2130706433/i.png'],
            'numérique hexa' => ['http://0x7f000001/i.png'],
            'numérique octal' => ['http://0177.0.0.1/i.png'],
            'numérique court' => ['http://127.1/i.png'],
            'numérique mixte' => ['http://0x7f.1/i.png'],
            'DNS sans réponse' => ['https://cdn.example.com/i.png'],
            'DNS → IPv4 privée' => ['https://cdn.example.com/i.png', ['cdn.example.com' => ['10.0.0.1']]],
            'DNS → IPv6 non globale' => ['https://cdn.example.com/i.png', ['cdn.example.com' => ['fd00::1']]],
            'DNS mixte dont une privée' => ['https://cdn.example.com/i.png', ['cdn.example.com' => ['93.184.216.34', '192.168.1.1']]],
        ];
    }

    public function test_memoise_les_verdicts_dns_par_hote(): void
    {
        $resolver = self::stubResolver(['cdn.example.com' => ['93.184.216.34']]);
        $policy = new ImageUrlPolicy(resolver: $resolver);

        $this->assertTrue($policy->isAllowed('https://cdn.example.com/a.png'));
        $this->assertTrue($policy->isAllowed('https://cdn.example.com/b.png'));
        $this->assertTrue($policy->isAllowed('https://cdn.example.com/c.png'));
        $this->assertSame(1, $resolver->calls, 'un seul appel DNS pour un hôte répété');
    }

    public function test_memoise_aussi_les_verdicts_negatifs(): void
    {
        $resolver = self::stubResolver(['internal.example.com' => ['10.0.0.1']]);
        $policy = new ImageUrlPolicy(resolver: $resolver);

        $this->assertFalse($policy->isAllowed('https://internal.example.com/a.png'));
        $this->assertFalse($policy->isAllowed('https://internal.example.com/b.png'));
        $this->assertSame(1, $resolver->calls, 'verdict négatif mémoïsé, pas de nouvelle résolution');
    }

    public function test_echec_ferme_sur_exception_du_resolveur(): void
    {
        $policy = new ImageUrlPolicy(resolver: self::stubResolver([], throws: true));
        $this->assertFalse($policy->isAllowed('https://cdn.example.com/i.png'));
    }

    public function test_plafond_de_50_images_par_document(): void
    {
        $policy = new ImageUrlPolicy(resolver: self::stubResolver(
            ['cdn.example.com' => ['93.184.216.34']],
        ));

        for ($i = 1; $i <= 50; $i++) {
            $this->assertTrue($policy->isAllowed("https://cdn.example.com/{$i}.png"), "image #{$i}");
        }
        $this->assertFalse($policy->isAllowed('https://cdn.example.com/51.png'));
    }

    public function test_plafond_de_25_hotes_uniques_par_document(): void
    {
        $map = [];
        for ($i = 1; $i <= 26; $i++) {
            $map["cdn{$i}.example.com"] = ['8.8.8.8'];
        }
        $resolver = self::stubResolver($map);
        $policy = new ImageUrlPolicy(resolver: $resolver);

        for ($i = 1; $i <= 25; $i++) {
            $this->assertTrue($policy->isAllowed("https://cdn{$i}.example.com/i.png"), "hôte #{$i}");
        }

        $this->assertFalse($policy->isAllowed('https://cdn26.example.com/i.png'));
        $this->assertSame(25, $resolver->calls, 'le 26e hôte unique ne déclenche pas de résolution');
    }

    public function test_reset_remet_les_compteurs_a_zero(): void
    {
        $resolver = self::stubResolver(['cdn.example.com' => ['93.184.216.34']]);
        $policy = new ImageUrlPolicy(resolver: $resolver);

        $this->assertTrue($policy->isAllowed('https://cdn.example.com/i.png'));
        $policy->reset();
        $this->assertTrue($policy->isAllowed('https://cdn.example.com/i.png'));
        $this->assertSame(2, $resolver->calls, 'après reset, le verdict est recalculé');
    }
}

/**
 * Résolveur factice typé — le compteur public rend les assertions
 * d'appels visibles pour PHPStan (pas de propriété sur l'interface).
 */
final class FakeImageHostResolver implements IpResolverInterface
{
    public int $calls = 0;

    /** @param array<string, list<string>> $map */
    public function __construct(
        private readonly array $map,
        private readonly bool $throws,
    ) {}

    public function resolve(string $host): array
    {
        $this->calls++;
        if ($this->throws) {
            throw new RuntimeException('résolveur en échec');
        }

        return $this->map[$host] ?? [];
    }
}
