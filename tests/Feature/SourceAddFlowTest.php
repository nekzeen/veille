<?php

namespace Tests\Feature;

use App\Models\Source;
use App\Models\SourceTheme;
use App\Models\User;
use App\Models\UserSource;
use App\Services\Feed\FeedClientInterface;
use App\Services\Feed\IpResolverInterface;
use App\Services\Feed\SecureFeedClient;
use Database\Seeders\SourceThemesSeeder;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Parcours complet d'ajout de source par détection (M8.5) :
 * `/sources/confirm` redétecte côté serveur — le navigateur n'est
 * jamais source de vérité pour le connecteur, l'URL ou l'état.
 */
class SourceAddFlowTest extends TestCase
{
    use RefreshDatabase;

    private function fakeHttp(array $responses): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $resolver = new class implements IpResolverInterface
        {
            public function resolve(string $host): array
            {
                return ['93.184.216.34'];
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

    private function confirm(User $user, array $payload): TestResponse
    {
        // M13.2b — thématique requise à la confirmation : le helper
        // fournit la première thématique du référentiel.
        $this->seedOnce();
        $payload += ['theme_id' => SourceTheme::firstOrFail()->id];

        return $this->actingAs($user)->postJson(route('sources.confirm'), $payload);
    }

    private function seedOnce(): void
    {
        if (! SourceTheme::exists()) {
            $this->seed(SourceThemesSeeder::class);
        }
    }

    public function test_ajout_rss_par_detection_complete(): void
    {
        $this->fakeHttp([self::ok(self::fixture('feeds/rss20_basic.xml'), 'application/rss+xml')]);
        $user = User::factory()->create();

        $this->confirm($user, ['url' => 'https://exemple.fr/feed.xml'])->assertOk()->assertJsonPath('status', 'ok');

        $source = Source::sole();
        $this->assertSame('rss', $source->connector);
        $this->assertSame('https://exemple.fr/feed.xml', $source->submitted_url);
        $this->assertSame('https://exemple.fr/feed.xml', $source->fetch_url);
        $this->assertSame('https://exemple.fr/feed.xml', $source->feed_url);
        $this->assertSame('validated', $source->config_state);
        $this->assertNotNull($source->detection);
        $this->assertSame(1, UserSource::where('user_id', $user->id)->count());
    }

    public function test_ajout_json_feed(): void
    {
        $this->fakeHttp([self::ok(self::fixture('detection/jsonfeed.json'), 'application/feed+json')]);

        $this->confirm(User::factory()->create(), ['url' => 'https://exemple.fr/feed.json'])->assertOk();

        $this->assertSame('json_feed', Source::sole()->connector);
    }

    public function test_ajout_html_avec_correction_des_selecteurs(): void
    {
        $this->fakeHttp([self::ok(self::fixture('detection/html_selector_page.html'), 'text/html')]);

        $this->confirm(User::factory()->create(), [
            'url' => 'https://exemple.fr/portail',
            'strategy' => 'selectors',
            'selectors' => ['item' => 'div.entry', 'link' => 'a.lnk'],
        ])->assertOk();

        $source = Source::sole();
        $this->assertSame('html', $source->connector);
        $this->assertSame('validated', $source->config_state);
        $this->assertSame('selectors', $source->config['strategy']);
        $this->assertSame('div.entry', $source->config['selectors']['item']);
        $this->assertSame(1, $source->config['version']);
    }

    public function test_page_html_via_alternate_flux_declare(): void
    {
        $this->fakeHttp([
            self::ok(self::fixture('detection/html_alternate.html'), 'text/html'),
            self::ok(self::fixture('feeds/rss20_basic.xml'), 'application/rss+xml'),
        ]);

        $this->confirm(User::factory()->create(), ['url' => 'https://exemple.fr/blog/'])->assertOk();

        $source = Source::sole();
        $this->assertSame('rss', $source->connector);
        $this->assertSame('https://exemple.fr/blog/', $source->submitted_url);
        $this->assertSame('https://exemple.fr/feed.xml', $source->fetch_url);
    }

    public function test_champs_falsifies_du_navigateur_ignores(): void
    {
        $this->fakeHttp([self::ok(self::fixture('feeds/rss20_basic.xml'), 'application/rss+xml')]);

        // Le navigateur tente d'imposer un autre connecteur/URL/état :
        // le serveur redétecte et ne retient que sa propre analyse.
        $this->confirm(User::factory()->create(), [
            'url' => 'https://exemple.fr/feed.xml',
            'connector' => 'api',
            'fetch_url' => 'http://169.254.169.254/steal',
            'config_state' => 'needs_review',
        ])->assertOk();

        $source = Source::sole();
        $this->assertSame('rss', $source->connector);
        $this->assertSame('https://exemple.fr/feed.xml', $source->fetch_url);
        $this->assertSame('validated', $source->config_state);
    }

    public function test_selecteur_invalide_refuse_422(): void
    {
        $this->fakeHttp([]);

        $this->confirm(User::factory()->create(), [
            'url' => 'https://exemple.fr/p',
            'selectors' => ['item' => 'div >>> hack'],
        ])->assertUnprocessable();
        $this->assertSame(0, Source::count());
    }

    public function test_source_inaccessible_ou_bloquee_non_enregistree(): void
    {
        $user = User::factory()->create();

        $this->fakeHttp([new Response(403)]);
        $this->confirm($user, ['url' => 'https://exemple.fr/feed'])->assertUnprocessable();
        $this->assertSame(0, Source::count());
        $this->assertSame(0, UserSource::count());

        $this->fakeHttp([self::ok(self::fixture('detection/html_captcha.html'), 'text/html')]);
        $this->confirm($user, ['url' => 'https://exemple.fr/recherche'])->assertUnprocessable()
            ->assertJsonPath('status', 'error');
        $this->assertSame(0, Source::count());
    }

    public function test_page_html_sans_aucun_article_refusee(): void
    {
        $this->fakeHttp([self::ok(self::fixture('detection/html_plain.html'), 'text/html')]);

        $this->confirm(User::factory()->create(), ['url' => 'https://exemple.fr/p'])->assertUnprocessable();
        $this->assertSame(0, Source::count());
    }

    public function test_source_existante_reutilisee_sans_ecraser_sa_config(): void
    {
        // M13.2c : seule une source du catalogue est réutilisable par un
        // autre utilisateur — une privée serait refusée (garde-fou).
        $source = Source::factory()->create([
            'is_catalogue' => true,
            'feed_url' => 'https://exemple.fr/page',
            'connector' => 'html',
            'config' => ['version' => 1, 'strategy' => 'selectors', 'enrich_pages' => 2, 'selectors' => ['item' => 'article']],
            'config_state' => 'validated',
        ]);
        $snapshot = DB::table('sources')->where('id', $source->id)->first();
        $this->fakeHttp([self::ok(self::fixture('detection/html_articles.html'), 'text/html')]);

        // Autres sélecteurs envoyés : la config validée existante prime.
        $this->confirm(User::factory()->create(), [
            'url' => 'https://exemple.fr/page',
            'selectors' => ['item' => 'div.autre'],
        ])->assertOk();

        $this->assertEquals($snapshot, DB::table('sources')->where('id', $source->id)->first());
        $this->assertSame(1, Source::count());
    }

    public function test_double_soumission_idempotente(): void
    {
        // Une seule liaison : la file de réponses sert les requêtes
        // successives (le re-binding n'est pas propagé aux requêtes).
        $this->fakeHttp(array_map(
            fn (): Response => self::ok(self::fixture('feeds/rss20_basic.xml'), 'application/rss+xml'),
            range(1, 2),
        ));
        $user = User::factory()->create();
        foreach ([0, 1] as $i) {
            $this->confirm($user, ['url' => 'https://exemple.fr/feed.xml'])->assertOk();
        }

        $this->assertSame(1, Source::count());
        $this->assertSame(1, UserSource::count());
    }

    public function test_isolation_abonnement_par_utilisateur(): void
    {
        [$a, $b] = [User::factory()->create(), User::factory()->create()];
        $this->fakeHttp(array_map(
            fn (): Response => self::ok(self::fixture('feeds/rss20_basic.xml'), 'application/rss+xml'),
            range(1, 2),
        ));
        foreach ([$a, $b] as $u) {
            $this->confirm($u, ['url' => 'https://exemple.fr/feed.xml'])->assertOk();
        }

        $this->assertSame(1, Source::count()); // source technique mutualisée
        $this->assertSame(2, UserSource::count());
        $this->assertSame(1, UserSource::where('user_id', $a->id)->count());
        $this->assertSame(1, UserSource::where('user_id', $b->id)->count());
    }

    public function test_confirm_exige_authentification(): void
    {
        $this->postJson(route('sources.confirm'), ['url' => 'https://exemple.fr/f'])->assertUnauthorized();
    }

    public function test_preview_avec_config_rejoue_l_extraction(): void
    {
        // Sans sélecteurs : la structure répétitive produit déjà 3 items.
        // Avec un sélecteur absurde : la nouvelle aperçu reflète la correction.
        $this->fakeHttp([
            self::ok(self::fixture('detection/html_selector_page.html'), 'text/html'),
            self::ok(self::fixture('detection/html_selector_page.html'), 'text/html'),
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('sources.preview'), [
            'url' => 'https://exemple.fr/portail',
            'selectors' => ['item' => 'article.inexistant'],
            'strategy' => 'selectors',
        ])->assertOk()->assertJsonPath('preview.items', []);
    }

    public function test_formulaire_historique_toujours_operationnel(): void
    {
        // Régression : l'ajout direct RSS (hors détection) inchangé.
        $this->actingAs(User::factory()->create())
            ->post(route('sources.personal'), ['feed_url' => 'https://exemple.fr/flux.xml'])
            ->assertRedirect(route('sources.index'));

        $source = Source::sole();
        $this->assertSame('https://exemple.fr/flux.xml', $source->feed_url);
        $this->assertSame('rss', $source->connector);
        $this->assertSame('validated', $source->config_state);
    }
}
