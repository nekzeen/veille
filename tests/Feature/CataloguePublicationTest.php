<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Source;
use App\Models\SourceTheme;
use App\Models\User;
use App\Models\UserSource;
use App\Models\Veille;
use App\Services\Feed\FeedClientInterface;
use App\Services\Feed\IpResolverInterface;
use App\Services\Feed\SecureFeedClient;
use App\Services\SourceSubscriptionService;
use Database\Seeders\SourceThemesSeeder;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** M13.2b — publication automatique au catalogue après validation. */
class CataloguePublicationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private SourceTheme $theme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SourceThemesSeeder::class);
        $this->user = User::factory()->create();
        $this->theme = SourceTheme::firstOrFail();
    }

    /** Détection HTTP simulée — SecureFeedClient sur MockHandler. */
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

    private static function fixture(string $path): string
    {
        return (string) file_get_contents(base_path('tests/fixtures/'.$path));
    }

    private static function okFeed(): Response
    {
        return new Response(200, ['Content-Type' => 'application/rss+xml; charset=utf-8'],
            self::fixture('feeds/rss20_basic.xml'));
    }

    private function svc(): SourceSubscriptionService
    {
        return $this->app->make(SourceSubscriptionService::class);
    }

    public function test_nouvelle_source_validee_est_publiee_au_catalogue(): void
    {
        $this->fakeHttp([self::okFeed()]);
        [$src] = $this->svc()->addDetectedSource($this->user, 'https://ex.com/feed.xml', null, $this->theme->id);

        $this->assertSame('active', $src->catalogue_state);
        $this->assertSame($this->theme->id, $src->theme_id);
        $this->assertDatabaseHas('user_sources', ['user_id' => $this->user->id, 'source_id' => $src->id]);
    }

    public function test_flux_invalide_aucune_publication(): void
    {
        $this->fakeHttp([new Response(200, ['Content-Type' => 'text/html'], '<html><body>pas un flux</body></html>')]);
        try {
            $this->svc()->addDetectedSource($this->user, 'https://ex.com/feed.xml', null, $this->theme->id);
            $this->fail('exception attendue');
        } catch (\Throwable) {
        }
        $this->assertSame(0, Source::count());
        $this->assertSame(0, UserSource::count());
    }

    public function test_source_catalogue_existante_reutilisee_sans_doublon(): void
    {
        $other = Source::factory()->create(['catalogue_state' => 'active',
            'feed_url' => 'https://ex.com/feed.xml', 'theme_id' => $this->theme->id]);
        $this->fakeHttp([self::okFeed()]);

        [$src] = $this->svc()->addDetectedSource($this->user, 'https://ex.com/feed.xml', null, SourceTheme::skip(1)->first()->id);

        $this->assertSame($other->id, $src->id);          // réutilisée
        $this->assertSame(1, Source::where('feed_url', 'https://ex.com/feed.xml')->count()); // pas de doublon
        $this->assertSame($this->theme->id, $src->theme_id); // thématique NON écrasée
    }

    public function test_source_retiree_republiee_apres_revalidation(): void
    {
        // M14 : plus de privée — une source retirée (commune) connue
        // par URL est revalidée puis réactivée ; même enregistrement.
        $src = Source::factory()->create([
            'is_catalogue' => true, 'catalogue_state' => 'withdrawn',
            'feed_url' => 'https://ex.com/feed.xml',
        ]);
        UserSource::create(['user_id' => $this->user->id, 'source_id' => $src->id, 'created_at' => now()]);
        $this->fakeHttp([self::okFeed()]);

        [$reused] = $this->svc()->addDetectedSource($this->user, 'https://ex.com/feed.xml', null, $this->theme->id);

        $this->assertSame('active', $reused->catalogue_state);     // réactivée
        $this->assertSame($src->id, $reused->id);                  // même enregistrement
        $this->assertSame(1, Source::count());                     // pas de doublon
    }

    public function test_source_privee_inaccessible_aux_autres_utilisateurs(): void
    {
        $src = Source::factory()->create(['catalogue_state' => 'withdrawn']);
        UserSource::create(['user_id' => $this->user->id, 'source_id' => $src->id, 'created_at' => now()]);
        $stranger = User::factory()->create();

        $this->expectException(\RuntimeException::class);
        $this->svc()->subscribe($stranger, $src); // garde d'isolation intacte
    }

    public function test_second_utilisateur_abonnable_au_catalogue(): void
    {
        $src = Source::factory()->create(['catalogue_state' => 'active']);
        $u2 = User::factory()->create();
        $this->svc()->subscribe($u2, $src);
        $this->assertDatabaseHas('user_sources', ['user_id' => $u2->id, 'source_id' => $src->id]);
    }

    public function test_appel_direct_sans_thematique_echoue_sans_donnees(): void
    {
        // M13.2b.1 — garantie au niveau du service : themeId non
        // nullable → TypeError immédiat, aucune source ni abonnement.
        $this->fakeHttp([self::okFeed()]);
        try {
            $this->svc()->addDetectedSource($this->user, 'https://ex.com/feed.xml', null, null); // @phpstan-ignore-line
            $this->fail('TypeError attendu');
        } catch (\TypeError) {
        }
        $this->assertSame(0, Source::count());
        $this->assertSame(0, UserSource::count());
    }

    public function test_theme_obligatoire_sur_confirmation(): void
    {
        $this->actingAs($this->user)
            ->postJson('/sources/confirm', ['url' => 'https://ex.com/feed.xml'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['theme_id']);
    }

    public function test_theme_inexistant_refuse(): void
    {
        $this->actingAs($this->user)
            ->postJson('/sources/confirm', ['url' => 'https://ex.com/feed.xml', 'theme_id' => 9999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['theme_id']);
    }

    public function test_custom_name_reste_personnel(): void
    {
        $src = Source::factory()->create(['catalogue_state' => 'active']);
        $u2 = User::factory()->create();
        UserSource::create(['user_id' => $u2->id, 'source_id' => $src->id, 'custom_name' => 'Mon flux', 'created_at' => now()]);

        $sub = $this->svc()->subscribe($this->user, $src);
        $this->assertNull($sub->custom_name); // le libellé de u2 n'est pas repris
        $this->assertSame('Mon flux', UserSource::where('user_id', $u2->id)->value('custom_name'));
    }

    public function test_rattachement_a_une_veille_conserve(): void
    {
        $v = Veille::factory()->create(['user_id' => $this->user->id]);
        $src = Source::factory()->create(['catalogue_state' => 'active']);
        DB::table('source_veille')->insert(['veille_id' => $v->id, 'source_id' => $src->id, 'user_id' => $this->user->id]);
        $this->assertDatabaseHas('source_veille', ['veille_id' => $v->id, 'source_id' => $src->id]);
    }

    public function test_source_retiree_abonnee_par_autrui_republiee_et_abonnee(): void
    {
        // M14 : plus de privée — une source retirée déjà abonnée par un
        // autre utilisateur est commune : la revalidation la réactive
        // et abonne le demandeur. Abonnements individuels, aucun lien
        // vers les données personnelles de l'autre utilisateur.
        $src = Source::factory()->create(['is_catalogue' => true,
            'catalogue_state' => 'withdrawn', 'feed_url' => 'https://ex.com/feed.xml']);
        $stranger = User::factory()->create();
        UserSource::create(['user_id' => $stranger->id, 'source_id' => $src->id, 'created_at' => now()]);
        $this->fakeHttp([self::okFeed()]);

        [$reused] = $this->svc()->addDetectedSource($this->user, 'https://ex.com/feed.xml', null, $this->theme->id);

        $this->assertSame('active', $reused->fresh()->catalogue_state);
        $this->assertDatabaseHas('user_sources', ['user_id' => $this->user->id, 'source_id' => $src->id]);
        $this->assertSame(2, UserSource::where('source_id', $src->id)->count());
        $this->assertSame(1, Source::count()); // pas de doublon
    }

    public function test_source_deja_suivie_pas_de_doublon_abonnement(): void
    {
        $src = Source::factory()->create(['catalogue_state' => 'active',
            'feed_url' => 'https://ex.com/feed.xml']);
        UserSource::create(['user_id' => $this->user->id, 'source_id' => $src->id, 'created_at' => now()]);
        $this->fakeHttp([self::okFeed()]);

        [$reused, $sub] = $this->svc()->addDetectedSource($this->user, 'https://ex.com/feed.xml', null, $this->theme->id);

        $this->assertSame($src->id, $reused->id);
        $this->assertFalse($sub->wasRecentlyCreated); // abonnement existant repris
        $this->assertSame(1, UserSource::where('user_id', $this->user->id)->count());
    }

    public function test_variantes_url_meme_source(): void
    {
        // Normalisation : majuscules scheme/host, port par défaut, slash vide.
        $this->fakeHttp([self::okFeed(), self::okFeed()]);
        [$s1] = $this->svc()->addDetectedSource($this->user, 'https://ex.com/feed.xml', null, $this->theme->id);
        [$s2] = $this->svc()->addDetectedSource($this->user, 'https://ex.com/feed.xml', null, $this->theme->id);
        $this->assertSame($s1->id, $s2->id);
        $this->assertSame(1, Source::count());
    }

    public function test_deux_flux_distincts_meme_domaine(): void
    {
        $this->fakeHttp([self::okFeed(), self::okFeed()]);
        [$a] = $this->svc()->addDetectedSource($this->user, 'https://ex.com/a.xml', null, $this->theme->id);
        [$b] = $this->svc()->addDetectedSource($this->user, 'https://ex.com/b.xml', null, $this->theme->id);
        $this->assertNotSame($a->id, $b->id); // jamais fusionnés
        $this->assertSame(2, Source::count());
    }

    public function test_reutilisation_ne_modifie_ni_catalogue_ni_theme(): void
    {
        $other = SourceTheme::skip(1)->first();
        $src = Source::factory()->create(['catalogue_state' => 'active',
            'feed_url' => 'https://ex.com/feed.xml', 'theme_id' => $other->id]);
        $this->fakeHttp([self::okFeed()]);

        [$reused] = $this->svc()->addDetectedSource($this->user, 'https://ex.com/feed.xml', null, $this->theme->id);
        $this->assertSame('active', $reused->catalogue_state);
        $this->assertSame($other->id, $reused->theme_id); // inchangée
    }

    public function test_pas_de_fuite_donnees_personnelles(): void
    {
        // Les infos « catalogue » n'exposent pas les abonnements d'autrui.
        $src = Source::factory()->create(['catalogue_state' => 'active']);
        $u2 = User::factory()->create();
        UserSource::create(['user_id' => $u2->id, 'source_id' => $src->id, 'custom_name' => 'Secret', 'created_at' => now()]);

        $this->actingAs($this->user);
        $subs = $this->user->userSources()->get();
        $this->assertFalse($subs->contains('custom_name', 'Secret'));
        $this->assertSame(0, $subs->where('user_id', '!=', $this->user->id)->count());
    }
}
