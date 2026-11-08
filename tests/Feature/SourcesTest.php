<?php

namespace Tests\Feature;

use App\Models\Source;
use App\Models\SourceTheme;
use App\Models\User;
use App\Models\UserSource;
use App\Services\Feed\FeedClientInterface;
use App\Services\Feed\IpResolverInterface;
use App\Services\Feed\SecureFeedClient;
use App\Services\SourceSubscriptionService;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SourcesTest extends TestCase
{
    use RefreshDatabase;

    private function catalogueSource(): Source
    {
        return Source::factory()->catalogue()->create();
    }

    private function fakeHttp(): void
    {
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/rss+xml'],
                (string) file_get_contents(base_path('tests/fixtures/feeds/rss20_basic.xml'))),
            new Response(200, ['Content-Type' => 'application/rss+xml'],
                (string) file_get_contents(base_path('tests/fixtures/feeds/rss20_basic.xml'))),
        ]));
        $resolver = new class implements IpResolverInterface
        {
            public function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        };
        $this->app->instance(FeedClientInterface::class,
            new SecureFeedClient($resolver, new Client(['handler' => $stack])));
    }

    private function themeId(): int
    {
        return SourceTheme::firstOrCreate(['slug' => 'autres'], ['name' => 'Autres'])->id;
    }

    public function test_catalogue_is_visible_to_authenticated_user(): void
    {
        $user = User::factory()->create();
        $catalogue = $this->catalogueSource();
        $personal = Source::factory()->create(['catalogue_state' => 'withdrawn']);

        $response = $this->actingAs($user)->get(route('sources.catalogue'));

        $response->assertOk()
            ->assertSee($catalogue->name)
            ->assertDontSee($personal->name);
    }

    public function test_guest_cannot_access_sources_pages(): void
    {
        $this->get(route('sources.catalogue'))->assertRedirect(route('login'));
        $this->get(route('sources.index'))->assertRedirect(route('login'));
    }

    public function test_user_can_subscribe_to_catalogue_source(): void
    {
        $user = User::factory()->create();
        $source = $this->catalogueSource();

        $this->actingAs($user)
            ->post(route('sources.subscribe', $source))
            ->assertRedirect();

        $this->assertDatabaseHas('user_sources', [
            'user_id' => $user->id,
            'source_id' => $source->id,
        ]);
    }

    public function test_unsubscribe_keeps_catalogue_source(): void
    {
        $user = User::factory()->create();
        $source = $this->catalogueSource();
        $subscription = UserSource::factory()->for($user)->for($source)->create();

        $this->actingAs($user)
            ->delete(route('sources.unsubscribe', $subscription))
            ->assertRedirect();

        $this->assertDatabaseMissing('user_sources', ['id' => $subscription->id]);
        $this->assertDatabaseHas('sources', ['id' => $source->id]);
        $this->assertNull($source->fresh()->orphaned_at);
    }

    public function test_user_can_add_source_by_url_validated(): void
    {
        // M14 : le formulaire par URL valide et détecte le flux puis
        // publie la source au catalogue commun — jamais privée.
        $user = User::factory()->create();
        $this->fakeHttp();

        $this->actingAs($user)
            ->post(route('sources.personal'), [
                'feed_url' => 'https://example.com/feed',
                'theme_id' => $this->themeId(),
            ])->assertRedirect(route('sources.index'));

        $this->assertDatabaseHas('sources', [
            'feed_url' => 'https://example.com/feed',
            'is_catalogue' => true,
            'catalogue_state' => 'active',
        ]);
        $this->assertDatabaseHas('user_sources', ['user_id' => $user->id]);
    }

    public function test_url_form_reuses_existing_common_source(): void
    {
        // M14 : URL connue → même enregistrement commun, pas de doublon.
        $existing = Source::factory()->catalogue()->create([
            'feed_url' => 'https://example.com/feed',
        ]);
        $user = User::factory()->create();
        $this->fakeHttp();

        $this->actingAs($user)
            ->post(route('sources.personal'), [
                'feed_url' => 'https://example.com/feed',
                'theme_id' => $this->themeId(),
            ]);

        $this->assertSame(1, Source::where('feed_url', 'https://example.com/feed')->count());
        $this->assertDatabaseHas('user_sources', [
            'user_id' => $user->id,
            'source_id' => $existing->id,
        ]);
    }

    public function test_withdrawn_source_of_another_revalidated_and_republished(): void
    {
        // M14 : plus de privée — une source retirée connue par URL est
        // revalidée puis réactivée ; l'abonné existant est conservé et
        // le demandeur est abonné. Jamais de fuite ni de doublon.
        $existing = Source::factory()->create([
            'feed_url' => 'https://example.com/feed',
            'is_catalogue' => true, 'catalogue_state' => 'withdrawn',
        ]);
        UserSource::create(['user_id' => User::factory()->create()->id,
            'source_id' => $existing->id, 'created_at' => now()]);
        $user = User::factory()->create();
        $this->fakeHttp();

        $this->actingAs($user)
            ->post(route('sources.personal'), [
                'feed_url' => 'https://example.com/feed',
                'theme_id' => $this->themeId(),
            ])->assertRedirect(route('sources.index'));

        $f = $existing->fresh();
        $this->assertSame(1, Source::count());
        $this->assertSame('active', $f->catalogue_state);
        $this->assertSame(2, UserSource::where('source_id', $existing->id)->count());
        $this->assertDatabaseHas('user_sources', ['user_id' => $user->id, 'source_id' => $existing->id]);
    }

    public function test_no_duplicate_subscription(): void
    {
        $user = User::factory()->create();
        $source = $this->catalogueSource();

        $this->actingAs($user)->post(route('sources.subscribe', $source));
        $this->actingAs($user)->post(route('sources.subscribe', $source));

        $this->assertSame(1, UserSource::where('user_id', $user->id)
            ->where('source_id', $source->id)->count());
    }

    public function test_user_cannot_delete_another_users_subscription(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $subscription = UserSource::factory()
            ->for($owner)->for($this->catalogueSource())->create();

        $this->actingAs($other)
            ->delete(route('sources.unsubscribe', $subscription))
            ->assertForbidden();

        $this->assertDatabaseHas('user_sources', ['id' => $subscription->id]);
    }

    public function test_invalid_feed_url_is_rejected(): void
    {
        $user = User::factory()->create();

        foreach (['not-a-url', 'ftp://ex.com/feed', 'javascript:alert(1)'] as $bad) {
            $this->actingAs($user)
                ->post(route('sources.personal'), ['feed_url' => $bad])
                ->assertSessionHasErrors('feed_url');
        }

        $this->assertSame(0, Source::count());
    }

    public function test_common_source_lifecycle_flags_on_unsubscribe(): void
    {
        // M14 : `orphaned_at` dormante ; le dernier lien retiré marque
        // l'inactivité catalogue — réabonnement possible (commune).
        $user = User::factory()->create();
        $source = Source::factory()->catalogue()->create();
        $subscription = UserSource::factory()->for($user)->for($source)->create();

        $this->actingAs($user)->delete(route('sources.unsubscribe', $subscription));
        $f = $source->fresh();
        $this->assertNull($f->orphaned_at);
        $this->assertNotNull($f->catalogue_inactive_at);

        // Réabonnement : source commune abonnable à nouveau.
        $this->actingAs($user)->post(route('sources.subscribe', $source))
            ->assertRedirect();
        $this->assertNull($source->fresh()->catalogue_inactive_at);
        $this->assertDatabaseHas('sources', ['id' => $source->id]);
    }

    public function test_unique_violation_detected_and_existing_record_reused(): void
    {
        $user = User::factory()->create();
        $source = $this->catalogueSource();

        // Vraie violation UNIQUE(user_id, source_id) produite en SQL brut.
        DB::insert(
            'INSERT INTO user_sources (user_id, source_id, created_at) VALUES (?, ?, ?)',
            [$user->id, $source->id, '2026-11-01 12:00:00'],
        );

        try {
            DB::insert(
                'INSERT INTO user_sources (user_id, source_id, created_at) VALUES (?, ?, ?)',
                [$user->id, $source->id, '2026-11-01 13:00:00'],
            );
            $this->fail('QueryException attendue');
        } catch (QueryException $e) {
            $this->assertTrue(SourceSubscriptionService::isUniqueViolation($e));
        }

        // Le service retrouve l'abonnement existant sans créer de doublon.
        $subscription = app(SourceSubscriptionService::class)
            ->subscribe($user, $source);
        $this->assertSame($source->id, $subscription->source_id);
        $this->assertSame(1, UserSource::where('user_id', $user->id)->count());
    }

    public function test_non_unique_sql_error_is_not_treated_as_duplicate(): void
    {
        // Erreur NOT NULL (pas une violation UNIQUE) : MariaDB 1048 /
        // SQLite « NOT NULL constraint failed » → jamais un doublon.
        try {
            DB::insert(
                'INSERT INTO user_sources (user_id, source_id, created_at) VALUES (?, ?, ?)',
                [1, null, '2026-11-01 12:00:00'],
            );
            $this->fail('QueryException attendue');
        } catch (QueryException $e) {
            $this->assertFalse(SourceSubscriptionService::isUniqueViolation($e));
        }
    }

    public function test_service_timestamps_are_written_in_utc(): void
    {
        $user = User::factory()->create();
        $source = Source::factory()->create(['catalogue_state' => 'withdrawn']);
        $subscription = UserSource::factory()->for($user)->for($source)->create();

        $this->actingAs($user)->delete(route('sources.unsubscribe', $subscription));

        $orphaned = Carbon::parse($source->fresh()->orphaned_at, 'UTC');
        $this->assertLessThan(
            30,
            abs(Carbon::now('UTC')->diffInSeconds($orphaned, false)),
            'orphaned_at doit être écrit en UTC',
        );
    }

    public function test_orphan_flag_cleared_when_still_used_elsewhere(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $source = Source::factory()->create(['catalogue_state' => 'withdrawn']);
        UserSource::factory()->for($userA)->for($source)->create();
        UserSource::factory()->for($userB)->for($source)->create();

        $subscriptionA = UserSource::where('user_id', $userA->id)->firstOrFail();
        $this->actingAs($userA)->delete(route('sources.unsubscribe', $subscriptionA));

        // Encore utilisée par B : pas d'orphelinage, pas de suppression.
        $this->assertNull($source->fresh()->orphaned_at);
        $this->assertDatabaseHas('sources', ['id' => $source->id]);
        $this->assertDatabaseHas('user_sources', ['user_id' => $userB->id]);
    }
}
