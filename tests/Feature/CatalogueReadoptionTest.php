<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Source;
use App\Models\SourceTheme;
use App\Models\User;
use App\Models\UserSource;
use App\Models\Veille;
use App\Services\Feed\FeedClientInterface;
use App\Services\Feed\IpResolverInterface;
use App\Services\Feed\SecureFeedClient;
use App\Services\SourceLifecycleService;
use App\Services\SourceSubscriptionService;
use Database\Seeders\SourceThemesSeeder;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M14 — réactivation des sources communes retirées (catalogue_state =
 * 'withdrawn'). Plus aucune source privée : une source retirée reste
 * commune et ne revient au catalogue actif qu'après revalidation
 * complète du flux + thématique.
 */
class CatalogueReadoptionTest extends TestCase
{
    use RefreshDatabase;

    private const FEED = 'https://ex.com/feed.xml';

    private SourceTheme $theme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SourceThemesSeeder::class);
        $this->theme = SourceTheme::orderBy('id')->firstOrFail();
    }

    private function fakeHttp(array $responses): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $resolver = new class implements IpResolverInterface
        {
            public function resolve(string $host): array
            {
                return ['ex.com' => ['93.184.216.34']][$host] ?? [];
            }
        };
        $this->app->instance(FeedClientInterface::class,
            new SecureFeedClient($resolver, new Client(['handler' => $stack])));
    }

    private static function okFeed(): Response
    {
        return new Response(200, ['Content-Type' => 'application/rss+xml; charset=utf-8'],
            (string) file_get_contents(base_path('tests/fixtures/feeds/rss20_basic.xml')));
    }

    /** Source commune retirée du catalogue actif, sans lien par défaut. */
    private function withdrawnSource(array $extra = []): Source
    {
        return Source::factory()->create(array_merge([
            'is_catalogue' => true, 'catalogue_state' => 'withdrawn', 'feed_url' => self::FEED,
            'orphaned_at' => now('UTC')->subDays(30), 'theme_id' => null,
        ], $extra));
    }

    private function confirm(User $u, array $payload = [])
    {
        return $this->actingAs($u)->postJson(route('sources.confirm'),
            $payload + ['url' => self::FEED, 'theme_id' => $this->theme->id]);
    }

    public function test_reactivation_source_retiree_apres_revalidation(): void
    {
        $s = $this->withdrawnSource();
        Article::factory()->count(3)->create(['source_id' => $s->id]);
        $this->fakeHttp([self::okFeed()]);
        $u = User::factory()->create();

        $this->confirm($u)->assertOk()->assertJsonPath('outcome', 'republished');

        $f = $s->fresh();
        $this->assertSame(1, Source::count());                       // même enregistrement, pas de doublon
        $this->assertTrue((bool) $f->is_catalogue);                  // toujours commune — jamais privée
        $this->assertSame('active', $f->catalogue_state);
        $this->assertSame($this->theme->id, $f->theme_id);
        $this->assertNull($f->catalogue_inactive_at);
        $this->assertNull($f->orphaned_at);                          // M13.1 réévalué (source commune)
        $this->assertSame(3, Article::where('source_id', $s->id)->count());
        $this->assertDatabaseHas('user_sources', ['user_id' => $u->id, 'source_id' => $s->id]);
    }

    public function test_source_retiree_avec_abonnement_existant_republiable(): void
    {
        // M14 : les liens sont conservés lors du retrait ; une source
        // retirée ayant déjà un abonné n'est pas « privée » — la
        // revalidation la réactive et ajoute l'abonnement du demandeur.
        $s = $this->withdrawnSource(['orphaned_at' => null]);
        $owner = User::factory()->create();
        UserSource::create(['user_id' => $owner->id, 'source_id' => $s->id, 'created_at' => now()]);
        $this->fakeHttp([self::okFeed()]);
        $stranger = User::factory()->create();

        $this->confirm($stranger)->assertOk()->assertJsonPath('outcome', 'republished');

        $f = $s->fresh();
        $this->assertSame('active', $f->catalogue_state);
        $this->assertSame(2, UserSource::where('source_id', $s->id)->count());
        $this->assertDatabaseHas('user_sources', ['user_id' => $stranger->id, 'source_id' => $s->id]);
    }

    public function test_source_retiree_rattachee_a_une_veille_republiable(): void
    {
        $s = $this->withdrawnSource(['orphaned_at' => null]);
        $v = Veille::factory()->create();
        DB::table('source_veille')->insert(['veille_id' => $v->id, 'source_id' => $s->id, 'user_id' => $v->user_id]);
        $this->fakeHttp([self::okFeed()]);

        $this->confirm(User::factory()->create())->assertOk()->assertJsonPath('outcome', 'republished');
        $this->assertSame('active', $s->fresh()->catalogue_state);
    }

    public function test_pas_de_reactivation_sans_validation_complete(): void
    {
        $s = $this->withdrawnSource();
        $u = User::factory()->create();

        // Flux invalide : refus, rien ne change.
        $this->fakeHttp([new Response(200, ['Content-Type' => 'text/html'], '<html><body>x</body></html>')]);
        $this->confirm($u)->assertStatus(422);
        // Sans thématique : refus de validation.
        $this->actingAs($u)->postJson(route('sources.confirm'), ['url' => self::FEED])->assertUnprocessable();
        // Abonnement direct : une source retirée n'est jamais abonnable.
        try {
            app(SourceSubscriptionService::class)->subscribe($u, $s);
            $this->fail('refus attendu');
        } catch (\RuntimeException) {
        }

        $f = $s->fresh();
        $this->assertSame('withdrawn', $f->catalogue_state);
        $this->assertSame(0, UserSource::count());
    }

    public function test_isolation_entre_deux_utilisateurs_apres_reactivation(): void
    {
        $s = $this->withdrawnSource();
        $this->fakeHttp([self::okFeed()]);
        [$a, $b] = User::factory()->count(2)->create();

        $this->confirm($a)->assertOk()->assertJsonPath('outcome', 'republished');
        UserSource::where('user_id', $a->id)->update(['custom_name' => 'Nom privé de A']);

        // B réutilise désormais la source commune, sans voir A.
        app(SourceSubscriptionService::class)->subscribe($b, $s->fresh());
        $this->assertNull(UserSource::where('user_id', $b->id)->value('custom_name'));
        $this->actingAs($b)->get('/sources/catalogue')->assertOk()->assertDontSee('Nom privé de A');
        $this->assertSame(1, Source::count());
        $this->assertSame(2, UserSource::where('source_id', $s->id)->count());
    }

    public function test_source_retiree_reste_commune_et_jamais_orpheline(): void
    {
        // M14 : la réévaluation M13.1 ne peut plus marquer une source
        // commune comme orpheline ; `orphaned_at` est dormante — une
        // éventuelle date résiduelle est effacée, jamais re-créée.
        $s = $this->withdrawnSource();
        app(SourceLifecycleService::class)->reevaluate($s);
        $this->assertNull($s->fresh()->orphaned_at);
        $this->assertSame('withdrawn', $s->fresh()->catalogue_state);
    }
}
