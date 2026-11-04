<?php

namespace Tests\Feature;

use App\Models\Source;
use App\Models\User;
use App\Services\Feed\Detection\SourceDetector;
use App\Services\Feed\FeedClientInterface;
use App\Services\Feed\IpResolverInterface;
use App\Services\Feed\SecureFeedClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * Détection et prévisualisation (M8.3) : vrai `SecureFeedClient`
 * (validation URL, DNS/IP, redirections, taille) branché sur un
 * handler Guzzle simulé et un résolveur DNS factice — aucune E/S
 * réseau, aucun site distant.
 */
class SourceDetectionTest extends TestCase
{
    use RefreshDatabase;

    private const PUBLIC_IP = '93.184.216.34';

    /** @var list<string> */
    private array $requested = [];

    /** @param  list<Response>  $responses */
    private function fakeHttp(array $responses, array $dns = []): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::tap(function (RequestInterface $request): void {
            $this->requested[] = (string) $request->getUri();
        }));
        $resolver = new class($dns + ['exemple.fr' => [self::PUBLIC_IP], 'flux.exemple.fr' => [self::PUBLIC_IP]]) implements IpResolverInterface
        {
            /** @param  array<string, list<string>>  $map */
            public function __construct(private array $map) {}

            public function resolve(string $host): array
            {
                return $this->map[$host] ?? [];
            }
        };

        $this->app->instance(FeedClientInterface::class, new SecureFeedClient($resolver, new Client(['handler' => $stack])));
    }

    private static function fixture(string $path): string
    {
        return (string) file_get_contents(base_path('tests/fixtures/'.$path));
    }

    private static function ok(string $body, string $type): Response
    {
        return new Response(200, ['Content-Type' => $type], $body);
    }

    private function detect(string $url): array
    {
        return app(SourceDetector::class)->detect($url)->toArray();
    }

    // ---------- Formats détectés ----------

    public function test_rss_valide_detecte(): void
    {
        $this->fakeHttp([self::ok(self::fixture('feeds/rss20_basic.xml'), 'application/rss+xml; charset=utf-8')]);

        $r = $this->detect('https://exemple.fr/feed.xml');

        $this->assertSame('detected', $r['status']);
        $this->assertSame('rss', $r['connector']);
        $this->assertTrue($r['connector_available']);
        $this->assertSame('high', $r['confidence']);
        $this->assertSame('detected', $r['config_state']);
        $this->assertSame('https://exemple.fr/feed.xml', $r['submitted_url']);
        $this->assertSame('https://exemple.fr/feed.xml', $r['fetch_url']);
        $this->assertSame('direct', $r['details']['via']);
    }

    public function test_atom_valide_detecte(): void
    {
        $this->fakeHttp([self::ok(self::fixture('feeds/atom_valid.xml'), 'application/atom+xml')]);

        $r = $this->detect('https://exemple.fr/atom');

        $this->assertSame('detected', $r['status']);
        $this->assertSame('atom', $r['connector']);
        $this->assertTrue($r['connector_available']);
    }

    public function test_flux_servi_en_text_html_reconnu_par_sa_racine(): void
    {
        $this->fakeHttp([self::ok(self::fixture('feeds/rss20_basic.xml'), 'text/html')]);

        $this->assertSame('rss', $this->detect('https://exemple.fr/feed')['connector']);
    }

    public function test_html_avec_flux_alternatif_propose_le_flux_verifie(): void
    {
        $this->fakeHttp([
            self::ok(self::fixture('detection/html_alternate.html'), 'text/html; charset=utf-8'),
            self::ok(self::fixture('feeds/rss20_basic.xml'), 'application/rss+xml'),
        ]);

        $r = $this->detect('https://exemple.fr/blog/');

        $this->assertSame('detected', $r['status']);
        $this->assertSame('rss', $r['connector']);
        $this->assertSame('https://exemple.fr/blog/', $r['submitted_url']);
        $this->assertSame('https://exemple.fr/feed.xml', $r['fetch_url']);
        $this->assertSame('html_alternate', $r['details']['via']);
        $this->assertSame(['https://exemple.fr/blog/', 'https://exemple.fr/feed.xml'], $this->requested);
    }

    public function test_flux_alternatif_invalide_retombe_sur_html(): void
    {
        $this->fakeHttp([
            self::ok(self::fixture('detection/html_alternate.html'), 'text/html'),
            new Response(404),
        ]);

        $r = $this->detect('https://exemple.fr/blog/');

        $this->assertSame('needs_review', $r['status']);
        $this->assertSame('html', $r['connector']);
        $this->assertSame(1, $r['details']['alternates_checked']);
    }

    public function test_html_sans_flux_necessite_revue_et_connecteur_futur(): void
    {
        $this->fakeHttp([self::ok(self::fixture('detection/html_plain.html'), 'text/html')]);

        $r = $this->detect('https://exemple.fr/recherche?q=x');

        $this->assertSame('needs_review', $r['status']);
        $this->assertSame('needs_review', $r['config_state']);
        $this->assertSame('html', $r['connector']);
        $this->assertTrue($r['connector_available']);
        $this->assertSame('low', $r['confidence']);
        $this->assertSame(0, $r['details']['extraction']['items']);
        $this->assertSame('Page de résultats', $r['details']['title']);
    }

    public function test_html_avec_donnees_structurees_confiance_moyenne(): void
    {
        $this->fakeHttp([self::ok(self::fixture('detection/html_jsonld.html'), 'text/html')]);

        $r = $this->detect('https://exemple.fr/actu');

        $this->assertSame('low', $r['confidence']);
        $this->assertEqualsCanonicalizing(['ItemList', 'NewsArticle', 'og:website'], $r['details']['structured_data']);
    }

    public function test_json_feed_reconnu_mais_connecteur_non_disponible(): void
    {
        $this->fakeHttp([self::ok(self::fixture('detection/jsonfeed.json'), 'application/feed+json')]);

        $r = $this->detect('https://exemple.fr/feed.json');

        $this->assertSame('detected', $r['status']);
        $this->assertSame('json_feed', $r['connector']);
        $this->assertTrue($r['connector_available']);
        $this->assertSame(2, $r['details']['item_count']);
    }

    public function test_json_quelconque_non_reconnu(): void
    {
        $this->fakeHttp([self::ok('{"data":[1,2]}', 'application/json')]);

        $this->assertSame('unrecognized', $this->detect('https://exemple.fr/api')['status']);
    }

    public function test_xml_non_flux_non_reconnu(): void
    {
        $this->fakeHttp([self::ok(self::fixture('feeds/invalid_not_feed.xml'), 'application/xml')]);

        $this->assertSame('unrecognized', $this->detect('https://exemple.fr/x.xml')['status']);
    }

    public function test_page_anti_robot_signalee_bloquee_sans_contournement(): void
    {
        $this->fakeHttp([self::ok(self::fixture('detection/html_captcha.html'), 'text/html')]);

        $r = $this->detect('https://exemple.fr/recherche');

        $this->assertSame('blocked', $r['status']);
        $this->assertNull($r['config_state']);
        $this->assertNull($r['connector']);
        $this->assertCount(1, $this->requested);
    }

    // ---------- Sécurité / erreurs ----------

    public function test_url_invalide_refusee_sans_requete(): void
    {
        $this->fakeHttp([]);

        $this->assertSame('invalid_url', $this->detect('ftp://exemple.fr/feed')['status']);
        $this->assertSame('invalid_url', $this->detect('https://user:pass@exemple.fr/feed')['status']);
        $this->assertSame([], $this->requested);
    }

    public function test_adresses_privees_et_reservees_refusees(): void
    {
        $this->fakeHttp([], ['interne.exemple.fr' => ['10.0.0.5'], 'local.exemple.fr' => ['127.0.0.1']]);

        foreach (['http://127.0.0.1/feed', 'http://169.254.169.254/latest', 'https://interne.exemple.fr/', 'https://local.exemple.fr/'] as $url) {
            $this->assertContains($this->detect($url)['status'], ['refused', 'invalid_url'], $url);
        }
        $this->assertSame([], $this->requested);
    }

    public function test_redirection_vers_adresse_interdite_refusee(): void
    {
        $this->fakeHttp([new Response(302, ['Location' => 'http://192.168.1.1/admin'])]);

        $this->assertSame('refused', $this->detect('https://exemple.fr/feed')['status']);
        $this->assertSame(['https://exemple.fr/feed'], $this->requested);
    }

    public function test_trop_de_redirections_refusees(): void
    {
        $this->fakeHttp(array_map(fn (): Response => new Response(301, ['Location' => 'https://exemple.fr/next']), range(1, 4)));

        $this->assertSame('refused', $this->detect('https://exemple.fr/feed')['status']);
        $this->assertCount(4, $this->requested);
    }

    public function test_reponse_http_en_erreur(): void
    {
        $this->fakeHttp([new Response(503)]);

        $r = $this->detect('https://exemple.fr/feed');

        $this->assertSame('unreachable', $r['status']);
        $this->assertSame(['http_status' => 503], $r['details']);
        $this->assertNotNull($r['message']);
    }

    public function test_reponse_trop_volumineuse(): void
    {
        $this->fakeHttp([new Response(200, ['Content-Type' => 'application/rss+xml', 'Content-Length' => '6000000'], 'x')]);

        $this->assertSame('unreachable', $this->detect('https://exemple.fr/feed')['status']);
    }

    public function test_type_de_contenu_incorrect(): void
    {
        $this->fakeHttp([self::ok("\x89PNG\r\n", 'image/png')]);

        $r = $this->detect('https://exemple.fr/image.png');

        $this->assertSame('unsupported_content_type', $r['status']);
        $this->assertSame('image/png', $r['details']['content_type']);
    }

    // ---------- Endpoints ----------

    public function test_endpoints_exigent_une_authentification(): void
    {
        $this->postJson(route('sources.detect'), ['url' => 'https://exemple.fr/feed'])->assertUnauthorized();
        $this->postJson(route('sources.preview'), ['url' => 'https://exemple.fr/feed'])->assertUnauthorized();
    }

    public function test_validation_stricte_des_parametres(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('sources.detect'), [])->assertUnprocessable()->assertJsonValidationErrors('url');
        $this->actingAs($user)->postJson(route('sources.detect'), ['url' => 'javascript:alert(1)'])->assertUnprocessable();
        $this->actingAs($user)->postJson(route('sources.preview'), ['url' => 'ftp://exemple.fr/x'])->assertUnprocessable();
        $this->actingAs($user)->postJson(route('sources.detect'), ['url' => 'https://exemple.fr/'.str_repeat('a', 1100)])->assertUnprocessable();
    }

    public function test_endpoint_detect_retourne_la_proposition(): void
    {
        $this->fakeHttp([self::ok(self::fixture('feeds/rss20_basic.xml'), 'application/rss+xml')]);

        $this->actingAs(User::factory()->create())
            ->postJson(route('sources.detect'), ['url' => 'https://exemple.fr/feed.xml'])
            ->assertOk()
            ->assertJson(['status' => 'detected', 'connector' => 'rss', 'config_state' => 'detected'])
            ->assertJsonMissingPath('preview');
    }

    public function test_previsualisation_limitee_normalisee_et_sans_persistance(): void
    {
        $items = '';
        for ($i = 1; $i <= 7; $i++) {
            $date = $i === 2 ? '' : '<pubDate>Wed, 0'.$i.' Oct 2026 10:00:00 GMT</pubDate>';
            $desc = $i === 3 ? '' : "<description>&lt;p&gt;Résumé &lt;b&gt;{$i}&lt;/b&gt;&lt;/p&gt;</description>";
            $items .= "<item><title>Article {$i}</title><link>https://exemple.fr/a{$i}</link><guid>https://exemple.fr/a{$i}</guid>{$desc}{$date}</item>";
        }
        $rss = '<?xml version="1.0"?><rss version="2.0"><channel><title>T</title><link>https://exemple.fr</link><description>D</description>'.$items.'</channel></rss>';
        $this->fakeHttp([self::ok($rss, 'application/rss+xml')]);
        $tables = ['sources', 'user_sources', 'articles', 'article_veille', 'article_user'];
        $before = array_map(fn (string $t): int => DB::table($t)->count(), $tables);

        $json = $this->actingAs(User::factory()->create())
            ->postJson(route('sources.preview'), ['url' => 'https://exemple.fr/feed.xml'])
            ->assertOk()
            ->assertJsonPath('status', 'detected')
            ->assertJsonPath('preview.status', 'ok')
            ->json();

        $this->assertCount(5, $json['preview']['items']);
        $this->assertSame([
            'title' => 'Article 1',
            'url' => 'https://exemple.fr/a1',
            'summary' => 'Résumé 1',
            'published_at' => '2026-10-01T10:00:00Z',
            'missing' => [],
        ], $json['preview']['items'][0]);
        $this->assertSame(['published_at'], $json['preview']['items'][1]['missing']);
        $this->assertSame(['summary'], $json['preview']['items'][2]['missing']);
        $this->assertSame($before, array_map(fn (string $t): int => DB::table($t)->count(), $tables));
    }

    public function test_previsualisation_format_futur_sans_resultats_fabriques(): void
    {
        $this->fakeHttp([self::ok(self::fixture('detection/html_plain.html'), 'text/html')]);

        $this->actingAs(User::factory()->create())
            ->postJson(route('sources.preview'), ['url' => 'https://exemple.fr/recherche'])
            ->assertOk()
            ->assertJsonPath('status', 'needs_review')
            ->assertJsonPath('preview.status', 'ok')
            ->assertJsonPath('preview.items', []);
    }

    public function test_previsualisation_en_echec_sans_details_techniques(): void
    {
        $this->fakeHttp([new Response(500, [], 'stack trace secret')]);

        $json = $this->actingAs(User::factory()->create())
            ->postJson(route('sources.preview'), ['url' => 'https://exemple.fr/feed'])
            ->assertOk()
            ->assertJsonPath('status', 'unreachable')
            ->assertJsonPath('preview.status', 'unavailable')
            ->json();

        $this->assertStringNotContainsString('secret', json_encode($json));
        $this->assertStringNotContainsString('Exception', json_encode($json));
    }

    public function test_detection_ne_modifie_pas_une_source_validee_existante(): void
    {
        $source = Source::factory()->create([
            'feed_url' => 'https://exemple.fr/feed.xml',
            'submitted_url' => 'https://exemple.fr/',
            'fetch_url' => 'https://exemple.fr/feed.xml',
            'connector' => 'rss',
            'config_state' => 'validated',
        ]);
        $snapshot = DB::table('sources')->where('id', $source->id)->first();
        $this->fakeHttp([
            self::ok(self::fixture('detection/html_plain.html'), 'text/html'),
            self::ok(self::fixture('detection/html_plain.html'), 'text/html'),
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('sources.detect'), ['url' => 'https://exemple.fr/feed.xml'])->assertOk()
            ->assertJsonPath('config_state', 'needs_review');
        $this->actingAs($user)->postJson(route('sources.preview'), ['url' => 'https://exemple.fr/feed.xml'])->assertOk();

        $this->assertEquals($snapshot, DB::table('sources')->where('id', $source->id)->first());
        $this->assertSame(1, DB::table('sources')->count());
    }

    public function test_limitation_de_frequence_par_utilisateur(): void
    {
        $this->fakeHttp(array_map(fn (): Response => self::ok(self::fixture('feeds/rss20_basic.xml'), 'application/rss+xml'), range(1, 10)));
        $user = User::factory()->create();

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user)->postJson(route('sources.detect'), ['url' => "https://exemple.fr/feed{$i}.xml"])->assertOk();
        }
        $this->actingAs($user)->postJson(route('sources.detect'), ['url' => 'https://exemple.fr/autre.xml'])->assertTooManyRequests();
    }
}
