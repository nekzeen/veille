<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Source;
use App\Models\SourceTheme;
use App\Models\User;
use App\Models\UserSource;
use App\Models\Veille;
use App\Services\Feed\Detection\SourceNotCollectableException;
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

/** M13.3c — réadoption des sources privées réellement inutilisées. */
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

    /** Source privée historique inutilisée, orpheline depuis 30 jours. */
    private function orphanPrivate(array $extra = []): Source
    {
        return Source::factory()->create(array_merge([
            'is_catalogue' => false, 'feed_url' => self::FEED,
            'orphaned_at' => now('UTC')->subDays(30), 'theme_id' => null,
        ], $extra));
    }

    private function confirm(User $u, array $payload = [])
    {
        return $this->actingAs($u)->postJson(route('sources.confirm'),
            $payload + ['url' => self::FEED, 'theme_id' => $this->theme->id]);
    }

    public function test_readoption_source_privee_inutilisee(): void
    {
        $s = $this->orphanPrivate(['catalogue_inactive_at' => null]);
        Article::factory()->count(3)->create(['source_id' => $s->id]);
        $this->fakeHttp([self::okFeed()]);
        $u = User::factory()->create();

        $this->confirm($u)->assertOk()->assertJsonPath('outcome', 'republished');

        $f = $s->fresh();
        $this->assertSame(1, Source::count());                       // même enregistrement, pas de doublon
        $this->assertTrue((bool) $f->is_catalogue);
        $this->assertSame($this->theme->id, $f->theme_id);           // thématique renseignée
        $this->assertNull($f->catalogue_inactive_at);
        $this->assertNull($f->orphaned_at);                          // M13.1 réévalué
        $this->assertSame(3, Article::where('source_id', $s->id)->count());
        $this->assertDatabaseHas('user_sources', ['user_id' => $u->id, 'source_id' => $s->id]);
    }

    public function test_source_retiree_et_orpheline_republiee_orphaned_at_remis_a_null(): void
    {
        $s = $this->orphanPrivate(['catalogue_inactive_at' => now('UTC')->subDays(120)]);
        $this->fakeHttp([self::okFeed()]);

        $this->confirm(User::factory()->create())->assertOk();

        $f = $s->fresh();
        $this->assertTrue((bool) $f->is_catalogue);
        $this->assertNull($f->catalogue_inactive_at);
        $this->assertNull($f->orphaned_at);
    }

    public function test_refus_source_privee_encore_abonnee(): void
    {
        $s = $this->orphanPrivate(['orphaned_at' => null]);
        $owner = User::factory()->create();
        UserSource::create(['user_id' => $owner->id, 'source_id' => $s->id, 'created_at' => now()]);
        $snap = DB::table('sources')->where('id', $s->id)->first();
        $this->fakeHttp([self::okFeed()]);
        $stranger = User::factory()->create();

        $this->confirm($stranger)->assertStatus(422)
            ->assertJsonPath('message', 'Cette URL ne peut pas être ajoutée.');

        $this->assertEquals($snap, DB::table('sources')->where('id', $s->id)->first());
        $this->assertSame(0, UserSource::where('user_id', $stranger->id)->count());
    }

    public function test_refus_source_rattachee_a_une_veille(): void
    {
        $s = $this->orphanPrivate(['orphaned_at' => null]);
        $v = Veille::factory()->create();
        DB::table('source_veille')->insert(['veille_id' => $v->id, 'source_id' => $s->id, 'user_id' => $v->user_id]);
        $this->fakeHttp([self::okFeed()]);
        $stranger = User::factory()->create();

        $this->confirm($stranger)->assertStatus(422)
            ->assertJsonPath('message', 'Cette URL ne peut pas être ajoutée.');
        $this->assertFalse((bool) $s->fresh()->is_catalogue);
        $this->assertSame(0, UserSource::count());
    }

    public function test_pas_de_publication_sans_validation_complete(): void
    {
        $s = $this->orphanPrivate();
        $u = User::factory()->create();

        // Flux invalide : refus, rien ne change.
        $this->fakeHttp([new Response(200, ['Content-Type' => 'text/html'], '<html><body>x</body></html>')]);
        $this->confirm($u)->assertStatus(422);
        // Sans thématique : refus de validation.
        $this->actingAs($u)->postJson(route('sources.confirm'), ['url' => self::FEED])->assertUnprocessable();
        // Ajout historique par URL (sans détection) : jamais de publication.
        try {
            app(SourceSubscriptionService::class)->addPersonalSource($u, self::FEED);
            $this->fail('refus attendu');
        } catch (SourceNotCollectableException) {
        }
        // Abonnement direct : refusé.
        try {
            app(SourceSubscriptionService::class)->subscribe($u, $s);
            $this->fail('refus attendu');
        } catch (\RuntimeException) {
        }

        $f = $s->fresh();
        $this->assertFalse((bool) $f->is_catalogue);
        $this->assertNotNull($f->orphaned_at);
        $this->assertSame(0, UserSource::count());
    }

    public function test_isolation_entre_deux_utilisateurs_apres_readoption(): void
    {
        $s = $this->orphanPrivate();
        $this->fakeHttp([self::okFeed()]);
        [$a, $b] = User::factory()->count(2)->create();

        $this->confirm($a)->assertOk()->assertJsonPath('outcome', 'republished');
        UserSource::where('user_id', $a->id)->update(['custom_name' => 'Nom privé de A']);

        // B réutilise désormais la source catalogue, sans voir A.
        app(SourceSubscriptionService::class)->subscribe($b, $s->fresh());
        $this->assertNull(UserSource::where('user_id', $b->id)->value('custom_name'));
        $this->actingAs($b)->get('/sources/catalogue')->assertOk()->assertDontSee('Nom privé de A');
        $this->assertSame(1, Source::count());
        $this->assertSame(2, UserSource::where('source_id', $s->id)->count());
    }

    public function test_source_privee_inutilisee_reste_suivie_sans_readoption(): void
    {
        // Aucune réadoption : la source reste orpheline, date conservée.
        $s = $this->orphanPrivate();
        $before = $s->orphaned_at;
        app(SourceLifecycleService::class)->reevaluate($s);
        $this->assertTrue($before->equalTo($s->fresh()->orphaned_at));
        $this->assertFalse((bool) $s->fresh()->is_catalogue);
    }
}
