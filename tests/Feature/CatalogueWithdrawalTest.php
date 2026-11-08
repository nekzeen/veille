<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Source;
use App\Models\SourceTheme;
use App\Models\User;
use App\Models\UserSource;
use App\Models\Veille;
use App\Services\CatalogueActivityService;
use App\Services\Feed\Detection\SourceNotCollectableException;
use App\Services\Feed\FeedClientInterface;
use App\Services\Feed\IpResolverInterface;
use App\Services\Feed\SecureFeedClient;
use App\Services\SourceSubscriptionService;
use App\Services\VeilleService;
use Database\Seeders\SourceThemesSeeder;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** M13.3b — retrait des sources inactives du catalogue après 90 jours. */
class CatalogueWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    private const FEED = 'https://ex.com/feed.xml';

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
    }

    private function svc(): CatalogueActivityService
    {
        return app(CatalogueActivityService::class);
    }

    private function idle(int $days, array $extra = []): Source
    {
        return Source::factory()->catalogue()->create(array_merge([
            'catalogue_inactive_at' => now('UTC')->subDays($days),
        ], $extra));
    }

    private function link(Source $s): UserSource
    {
        return UserSource::create(['user_id' => User::factory()->create()->id,
            'source_id' => $s->id, 'created_at' => now()]);
    }

    private function withdrawn(array $extra = []): Source
    {
        return Source::factory()->create(array_merge([
            'is_catalogue' => false, 'feed_url' => self::FEED,
            'catalogue_inactive_at' => now('UTC')->subDays(120),
        ], $extra));
    }

    private function run_(): void
    {
        $this->artisan('catalogue:reconcile-activity', ['--execute' => true])->assertExitCode(0);
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

    // ---- Seuil des 90 jours ----

    public function test_moins_de_90_jours_conservee(): void
    {
        $s = $this->idle(89);
        $this->run_();
        $this->assertTrue((bool) $s->fresh()->is_catalogue);
    }

    public function test_exactement_90_jours_retiree(): void
    {
        $s = $this->idle(90);
        $this->run_();
        $this->assertFalse((bool) $s->fresh()->is_catalogue);
    }

    public function test_plus_de_90_jours_retiree_sans_suppression(): void
    {
        $s = $this->idle(200, ['orphaned_at' => null]);
        Article::factory()->count(2)->create(['source_id' => $s->id]);
        $this->run_();

        $fresh = $s->fresh();
        $this->assertFalse((bool) $fresh->is_catalogue);
        $this->assertNotNull($fresh->catalogue_inactive_at); // marque « retirée »
        $this->assertNull($fresh->orphaned_at);               // jamais touché
        $this->assertSame(1, Source::count());
        $this->assertSame(2, Article::where('source_id', $s->id)->count());
    }

    public function test_avec_abonnement_conservee(): void
    {
        $s = $this->idle(120);
        $this->link($s);
        $this->run_();
        $this->assertTrue((bool) $s->fresh()->is_catalogue);
        $this->assertNull($s->fresh()->catalogue_inactive_at); // marqueur périmé corrigé
        $this->assertSame(1, UserSource::count());
    }

    public function test_avec_rattachement_veille_conservee(): void
    {
        $s = $this->idle(120);
        $v = Veille::factory()->create();
        DB::table('source_veille')->insert(['veille_id' => $v->id, 'source_id' => $s->id, 'user_id' => $v->user_id]);
        $this->run_();
        $this->assertTrue((bool) $s->fresh()->is_catalogue);
        $this->assertSame(1, DB::table('source_veille')->count());
    }

    public function test_source_privee_jamais_concernee(): void
    {
        $p = Source::factory()->create(['is_catalogue' => false,
            'catalogue_inactive_at' => now('UTC')->subDays(300), 'orphaned_at' => now('UTC')->subDays(10)]);
        $snap = DB::table('sources')->where('id', $p->id)->first();
        $this->run_();
        $this->assertEquals($snap, DB::table('sources')->where('id', $p->id)->first());
    }

    public function test_utilisation_pendant_le_delai_reinitialise(): void
    {
        $s = $this->idle(60);
        $u = User::factory()->create();
        $sub = app(SourceSubscriptionService::class)->subscribe($u, $s);
        $this->assertNull($s->fresh()->catalogue_inactive_at);

        app(SourceSubscriptionService::class)->unsubscribe($u, $sub);
        $this->travel(89)->days();
        $this->run_();
        $this->assertTrue((bool) $s->fresh()->is_catalogue); // nouveau délai : 89 j
        $this->travel(1)->days();
        $this->run_();
        $this->assertFalse((bool) $s->fresh()->is_catalogue);
    }

    // ---- Concurrence (entrelacements déterministes) ----

    public function test_lien_apparu_entre_selection_et_retrait_conserve(): void
    {
        $s = $this->idle(120);
        $this->assertSame([$s->id], $this->svc()->withdrawalCandidateIds());

        $this->link($s); // abonnement validé par une autre requête

        $this->assertFalse($this->svc()->withdrawIfStillInactive($s->id));
        $this->assertTrue((bool) $s->fresh()->is_catalogue);
    }

    public function test_rattachement_veille_concurrent_conserve(): void
    {
        $s = $this->idle(120);
        $this->assertSame([$s->id], $this->svc()->withdrawalCandidateIds());

        $u = User::factory()->create();
        app(VeilleService::class)->create($u, ['name' => 'V'], ['ia'], [$s->id]);

        $this->assertFalse($this->svc()->withdrawIfStillInactive($s->id));
        $this->assertTrue((bool) $s->fresh()->is_catalogue);
    }

    public function test_abonnement_apres_retrait_valide_refuse(): void
    {
        $s = $this->idle(120);
        $this->assertTrue($this->svc()->withdrawIfStillInactive($s->id));

        // L'abonné concurrent arrive après le commit du retrait : il
        // relit la ligne verrouillée → source privée → refus.
        $this->expectException(\RuntimeException::class);
        try {
            app(SourceSubscriptionService::class)->subscribe(User::factory()->create(), $s);
        } finally {
            $this->assertSame(0, UserSource::count());
            $this->assertFalse((bool) $s->fresh()->is_catalogue);
        }
    }

    public function test_verrous_emis_sur_mariadb(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite : pas de verrou de ligne (écritures sérialisées par base).');
        }
        $s = $this->idle(120);
        DB::enableQueryLog();
        $this->svc()->withdrawIfStillInactive($s->id);
        $s2 = Source::factory()->catalogue()->create();
        app(SourceSubscriptionService::class)->subscribe(User::factory()->create(), $s2);
        $sql = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();

        $this->assertGreaterThanOrEqual(2, substr_count(strtolower($sql), 'for update'));
        $this->assertStringContainsString('lock in share mode', strtolower($sql));
    }

    // ---- Réactivation ----

    public function test_reutilisation_ordinaire_sans_republication(): void
    {
        $s = $this->withdrawn();
        $u = User::factory()->create();

        try {
            app(SourceSubscriptionService::class)->subscribe($u, $s);
            $this->fail('refus attendu');
        } catch (\RuntimeException) {
        }
        try {
            app(SourceSubscriptionService::class)->addPersonalSource($u, self::FEED);
            $this->fail('refus attendu');
        } catch (SourceNotCollectableException) {
        }

        $this->assertFalse((bool) $s->fresh()->is_catalogue);
        $this->assertNotNull($s->fresh()->catalogue_inactive_at);
        $this->assertSame(0, UserSource::count());
    }

    public function test_republication_explicite_apres_validation(): void
    {
        $this->seed(SourceThemesSeeder::class);
        [$t1, $t2] = SourceTheme::orderBy('id')->take(2)->get()->all();
        $s = $this->withdrawn(['theme_id' => $t1->id]);
        $this->fakeHttp([self::okFeed()]);

        $this->actingAs(User::factory()->create())
            ->postJson(route('sources.confirm'), ['url' => self::FEED, 'theme_id' => $t2->id])
            ->assertOk()->assertJsonPath('outcome', 'republished');

        $fresh = $s->fresh();
        $this->assertSame(1, Source::count());              // même enregistrement
        $this->assertTrue((bool) $fresh->is_catalogue);
        $this->assertNull($fresh->catalogue_inactive_at);
        $this->assertSame($t1->id, $fresh->theme_id);       // thématique existante conservée
        $this->assertSame(1, UserSource::where('source_id', $s->id)->count());
    }

    public function test_republication_refusee_si_flux_invalide(): void
    {
        $this->seed(SourceThemesSeeder::class);
        $s = $this->withdrawn();
        $this->fakeHttp([new Response(200, ['Content-Type' => 'text/html'], '<html><body>x</body></html>')]);

        $this->actingAs(User::factory()->create())
            ->postJson(route('sources.confirm'), ['url' => self::FEED, 'theme_id' => SourceTheme::firstOrFail()->id])
            ->assertStatus(422);

        $this->assertFalse((bool) $s->fresh()->is_catalogue);
        $this->assertSame(0, UserSource::count());
    }

    public function test_source_privee_historique_jamais_republiee(): void
    {
        $this->seed(SourceThemesSeeder::class);
        $u = User::factory()->create();
        $s = Source::factory()->create(['is_catalogue' => false, 'feed_url' => self::FEED]);
        UserSource::create(['user_id' => $u->id, 'source_id' => $s->id, 'created_at' => now()]);
        $this->fakeHttp([self::okFeed()]);

        $this->actingAs($u)->postJson(route('sources.confirm'),
            ['url' => self::FEED, 'theme_id' => SourceTheme::firstOrFail()->id])
            ->assertOk()->assertJsonPath('outcome', 'already_subscribed');
        $this->assertFalse((bool) $s->fresh()->is_catalogue);
    }

    // ---- updated_at ----

    public function test_updated_at_jamais_modifie(): void
    {
        $this->seed(SourceThemesSeeder::class);
        $past = Carbon::parse('2026-01-01 00:00:00', 'UTC');
        $s = Source::factory()->catalogue()->create();
        DB::table('sources')->where('id', $s->id)->update(['updated_at' => $past]);

        $this->svc()->reevaluate($s);                          // marquage
        DB::table('sources')->where('id', $s->id)->update(['catalogue_inactive_at' => now('UTC')->subDays(91)]);
        $this->assertTrue($this->svc()->withdrawIfStillInactive($s->id)); // retrait
        $this->svc()->republish($s->fresh(), SourceTheme::firstOrFail()->id); // republication

        $this->assertSame($past->toDateTimeString(),
            Carbon::parse(DB::table('sources')->where('id', $s->id)->value('updated_at'))->toDateTimeString());
    }

    // ---- Commande ----

    public function test_dry_run_sans_ecriture(): void
    {
        $s = $this->idle(120);
        $snap = DB::table('sources')->where('id', $s->id)->first();
        $this->artisan('catalogue:reconcile-activity')
            ->expectsOutputToContain('à retirer (≥ 90 j)    : 1')
            ->expectsOutputToContain('aucune écriture (dry-run)')
            ->assertExitCode(0);
        $this->assertEquals($snap, DB::table('sources')->where('id', $s->id)->first());
    }

    public function test_source_id_cible_le_retrait(): void
    {
        $a = $this->idle(120);
        $b = $this->idle(120);
        $this->artisan('catalogue:reconcile-activity', ['--execute' => true, '--source-id' => $a->id])->assertExitCode(0);
        $this->assertFalse((bool) $a->fresh()->is_catalogue);
        $this->assertTrue((bool) $b->fresh()->is_catalogue);
    }

    // ---- Planification ----

    private function scheduledEvent(): Event
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'catalogue:reconcile-activity'));
        $this->assertCount(1, $events);

        return $events->first();
    }

    public function test_planification_quotidienne_sans_chevauchement_desactivee_par_defaut(): void
    {
        $e = $this->scheduledEvent();
        $this->assertStringContainsString('--execute --scheduled', (string) $e->command);
        $this->assertStringNotContainsString('--force', (string) $e->command);
        $this->assertSame('15 3 * * *', $e->expression);
        $this->assertSame('Europe/Paris', $e->timezone);
        $this->assertTrue($e->withoutOverlapping);
        $this->assertFalse($e->filtersPass($this->app));

        config(['catalogue.scheduled.enabled' => true]);
        $this->assertTrue($e->filtersPass($this->app));
    }

    public function test_scheduled_refuse_si_desactive(): void
    {
        $s = $this->idle(120);
        $this->artisan('catalogue:reconcile-activity', ['--execute' => true, '--scheduled' => true])->assertExitCode(2);
        $this->assertTrue((bool) $s->fresh()->is_catalogue);
    }

    public function test_scheduled_refuse_si_base_differente(): void
    {
        $s = $this->idle(120);
        config(['catalogue.scheduled.enabled' => true, 'catalogue.scheduled.database' => 'veille_db']);
        $this->artisan('catalogue:reconcile-activity', ['--execute' => true, '--scheduled' => true])->assertExitCode(2);
        config(['catalogue.scheduled.database' => '']);
        $this->artisan('catalogue:reconcile-activity', ['--execute' => true, '--scheduled' => true])->assertExitCode(2);
        $this->assertTrue((bool) $s->fresh()->is_catalogue);
    }

    public function test_scheduled_autorise_si_active_et_base_exacte(): void
    {
        $s = $this->idle(120);
        config(['catalogue.scheduled.enabled' => true,
            'catalogue.scheduled.database' => DB::connection()->getDatabaseName()]);
        $this->artisan('catalogue:reconcile-activity', ['--execute' => true, '--scheduled' => true])->assertExitCode(0);
        $this->assertFalse((bool) $s->fresh()->is_catalogue);
    }

    public function test_scheduled_options_incompatibles(): void
    {
        $this->assertSame(2, Artisan::call('catalogue:reconcile-activity', ['--scheduled' => true]));
        $this->assertSame(2, Artisan::call('catalogue:reconcile-activity',
            ['--execute' => true, '--scheduled' => true, '--force' => true]));
    }

    public function test_scheduled_sur_base_distincte_refuse_meme_active(): void
    {
        // Configuration activée pour veille_test, mais base connectée différente.
        $path = sys_get_temp_dir().'/catalogue_sched_'.uniqid().'.sqlite';
        touch($path);
        $orig = config('database.default');
        config(['database.connections.catalogue_sched' => ['driver' => 'sqlite', 'database' => $path],
            'catalogue.scheduled.enabled' => true, 'catalogue.scheduled.database' => 'veille_test']);
        DB::setDefaultConnection('catalogue_sched');
        try {
            $this->assertSame(2, Artisan::call('catalogue:reconcile-activity', ['--execute' => true, '--scheduled' => true]));
        } finally {
            DB::setDefaultConnection($orig);
            DB::purge('catalogue_sched');
            @unlink($path);
        }
    }
}
