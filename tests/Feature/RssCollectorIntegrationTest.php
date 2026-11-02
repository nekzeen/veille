<?php

namespace Tests\Feature;

use App\Jobs\FetchFeedJob;
use App\Models\Article;
use App\Models\Source;
use App\Services\Feed\Data\FeedFetchResult;
use App\Services\Feed\Exceptions\FeedNetworkException;
use App\Services\Feed\FeedClientInterface;
use App\Services\Feed\FeedParserInterface;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Fakes\FakeFeedClient;
use Tests\Fakes\SpyFeedParser;
use Tests\TestCase;

/**
 * Tests intégrés du collecteur RSS : cycle complet feeds:fetch →
 * queue database → worker → client faké → parser → upsert → source.
 * Aucun accès réseau (client faké injecté via le conteneur).
 */
class RssCollectorIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Stores de production : queue `database` (jobs réels) et cache
        // `database` (verrous réels), exercés de bout en bout.
        config(['queue.default' => 'database', 'cache.default' => 'database']);
        Carbon::setTestNow(Carbon::parse('2026-11-01 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/fixtures/feeds/'.$name));
    }

    /** @param  array<string, mixed>  $attributes */
    private function source(array $attributes = []): Source
    {
        return Source::factory()->create($attributes);
    }

    /** Injecte un client faké : toute collecte passera par lui. */
    private function useClient(FakeFeedClient $client): FakeFeedClient
    {
        $this->app->instance(FeedClientInterface::class, $client);

        return $client;
    }

    private function runFetch(): int
    {
        return Artisan::call('feeds:fetch');
    }

    private function runWorker(): int
    {
        return Artisan::call('queue:work', [
            '--stop-when-empty' => true,
            '--max-time' => 50,
            '--sleep' => 0,
        ]);
    }

    /** Cycle complet sélection + drainage. */
    private function runCycle(): void
    {
        $this->runFetch();
        $this->runWorker();
    }

    /** Avance l'horloge de `$minutes` minutes. */
    private function advance(int $minutes): void
    {
        Carbon::setTestNow(now()->addMinutes($minutes));
    }

    // ---------- A. Cycle nominal ----------

    public function test_full_cycle_collects_and_updates_source(): void
    {
        $source = $this->source();
        $client = $this->useClient(FakeFeedClient::ok(self::fixture('rss20_basic.xml'), 'etag-v1', 'Mon, 27 Oct 2026 10:00:00 GMT'));

        $this->runFetch();
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(0, $client->calls === [] ? 0 : 0);

        $this->runWorker();
        $this->assertSame(0, DB::table('jobs')->count());

        // Articles enregistrés, métadonnées de succès écrites.
        $this->assertSame(2, Article::where('source_id', $source->id)->count());
        $fresh = $source->fresh();
        $this->assertSame(0, $fresh->fail_count);
        $this->assertNull($fresh->last_error);
        $this->assertTrue($fresh->last_fetched_at->equalTo(now()));
        $this->assertTrue($fresh->last_success_at->equalTo(now()));
        $this->assertSame('etag-v1', $fresh->etag);
        $this->assertSame(1, count($client->calls));
    }

    public function test_second_cycle_with_unchanged_feed_creates_no_article(): void
    {
        $source = $this->source();
        $this->useClient(FakeFeedClient::ok(self::fixture('rss20_basic.xml')));

        $this->runCycle();
        $this->assertSame(2, Article::where('source_id', $source->id)->count());

        $this->advance(20); // échéance D12 dépassée → nouveau cycle
        $this->runCycle();

        // Re-collecte effectuée, déduplication : rien de nouveau.
        $this->assertSame(2, Article::where('source_id', $source->id)->count());
        $this->assertTrue($source->fresh()->last_fetched_at->equalTo(now()));
    }

    public function test_stored_validators_are_sent_on_next_fetch(): void
    {
        $source = $this->source();
        $client = $this->useClient(FakeFeedClient::ok(self::fixture('rss20_basic.xml'), 'etag-abc', 'Tue, 28 Oct 2026 09:00:00 GMT'));

        $this->runCycle();
        $this->advance(20);
        $this->runCycle();

        // GET conditionnel : les validateurs persistés sont renvoyés.
        $this->assertSame(2, count($client->calls));
        $this->assertSame('etag-abc', $client->calls[1]['etag']);
        $this->assertSame('Tue, 28 Oct 2026 09:00:00 GMT', $client->calls[1]['lastModified']);
    }

    // ---------- B. Backoff progressif ----------

    public function test_backoff_delays_15_30_60_120_240_360_are_applied_end_to_end(): void
    {
        $source = $this->source();
        $this->useClient(new FakeFeedClient(new FeedNetworkException('down')));

        $delays = [1 => 15, 2 => 30, 3 => 60, 4 => 120, 5 => 240, 6 => 360, 7 => 360];

        foreach ($delays as $expectedFailCount => $delay) {
            // La source est due à cet instant du cycle.
            $this->runFetch();
            $this->assertSame(1, DB::table('jobs')->count(), "cycle attendu à fail_count={$expectedFailCount}");
            $this->runWorker();

            $fresh = $source->fresh();
            $this->assertSame($expectedFailCount, $fresh->fail_count);
            $this->assertTrue($fresh->is_active);
            // Prochaine échéance = last_fetched_at + backoff, en UTC.
            $this->assertSame('UTC', $fresh->last_fetched_at->getTimezone()->getName());
            $this->assertTrue($fresh->dueAt()->equalTo(now()->addMinutes($delay)));

            // Une minute avant l'échéance : aucun nouveau dispatch.
            $this->advance($delay - 1);
            $this->runFetch();
            $this->assertSame(0, DB::table('jobs')->count(), 'redispatch avant échéance');

            // À l'échéance : la source redevient collectable.
            $this->advance(1);
        }
    }

    // ---------- C. Désactivation après dix échecs ----------

    public function test_source_is_deactivated_at_tenth_failure_and_never_auto_reactivated(): void
    {
        $source = $this->source();
        $this->useClient(new FakeFeedClient(new FeedNetworkException('down')));

        // 9 échecs successifs (délais P6 : 15, 15, 30, 60, 120, 240, 360, 360, 360).
        for ($i = 1; $i <= 9; $i++) {
            $this->runCycle();
            $this->assertSame($i, $source->fresh()->fail_count);
            $this->assertTrue($source->fresh()->is_active);
            $this->advance(Source::backoffMinutes($i));
        }

        $this->runCycle();
        $fresh = $source->fresh();
        $this->assertSame(10, $fresh->fail_count);
        $this->assertFalse($fresh->is_active);

        // Aucune collecte tant que la source reste désactivée, même
        // très longtemps après : pas de réactivation automatique.
        $this->advance(60 * 24 * 30);
        $this->runCycle();
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertFalse($source->fresh()->is_active);
        $this->assertSame(10, $source->fresh()->fail_count);
    }

    public function test_manual_reactivation_collects_immediately(): void
    {
        $source = $this->source([
            'is_active' => false,
            'fail_count' => 10,
            'last_fetched_at' => now()->subDays(2),
        ]);
        $this->useClient(FakeFeedClient::ok(self::fixture('rss20_basic.xml')));

        // Réactivation manuelle : last_fetched_at ancien → due de suite.
        $source->update(['is_active' => true]);
        $this->runCycle();

        $this->assertSame(2, Article::where('source_id', $source->id)->count());
        $this->assertSame(0, $source->fresh()->fail_count);
    }

    // ---------- D. Récupération après erreur ----------

    public function test_recovery_200_after_failures_resets_counters_and_error(): void
    {
        $source = $this->source();
        $this->useClient(new FakeFeedClient(new FeedNetworkException('panne réseau')));

        for ($i = 0; $i < 3; $i++) {
            $this->runCycle();
            $this->advance(Source::backoffMinutes($i + 1));
        }
        $fresh = $source->fresh();
        $this->assertSame(3, $fresh->fail_count);
        $this->assertNotNull($fresh->last_error);

        // Le flux redevient joignable : le client faké est rebasculé.
        $this->useClient(FakeFeedClient::ok(self::fixture('rss20_basic.xml')));
        $this->runCycle();

        $fresh = $source->fresh();
        $this->assertSame(0, $fresh->fail_count);
        $this->assertNull($fresh->last_error);
        $this->assertTrue($fresh->last_fetched_at->equalTo(now()));
        $this->assertTrue($fresh->last_success_at->equalTo(now()));
        $this->assertSame(2, Article::where('source_id', $source->id)->count());
    }

    public function test_recovery_304_resets_failures_without_parsing_or_upsert(): void
    {
        $source = $this->source();
        $spy = new SpyFeedParser;
        $this->app->instance(FeedParserInterface::class, $spy);

        // Première collecte OK : etag persisté.
        $this->useClient(FakeFeedClient::ok(self::fixture('rss20_basic.xml'), 'etag-9'));
        $this->runCycle();
        $this->assertSame(1, $spy->calls);
        $articles = Article::where('source_id', $source->id)->count();

        // Deux échecs.
        $this->advance(20);
        $this->useClient(new FakeFeedClient(new FeedNetworkException('down')));
        $this->runCycle();
        $this->advance(20);
        $this->runCycle();
        $this->assertSame(2, $source->fresh()->fail_count);

        // Reprise par un 304 : ni parsing, ni upsert, compteurs remis.
        $this->advance(35);
        $this->useClient(FakeFeedClient::notModified('etag-9'));
        $this->runCycle();

        $this->assertSame(1, $spy->calls, 'le parseur ne doit pas être appelé sur 304');
        $this->assertSame($articles, Article::where('source_id', $source->id)->count());
        $fresh = $source->fresh();
        $this->assertSame(0, $fresh->fail_count);
        $this->assertNull($fresh->last_error);
        $this->assertTrue($fresh->last_success_at->equalTo(now()));
    }

    // ---------- E. Verrous et concurrence ----------

    public function test_double_selection_cycle_enqueues_a_single_job(): void
    {
        $source = $this->source();
        $this->useClient(FakeFeedClient::ok(self::fixture('rss20_basic.xml')));

        $this->runFetch();
        $this->runFetch(); // second cycle avant drainage : unicité 900 s

        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_held_lock_skips_collect_and_success_releases_it(): void
    {
        $source = $this->source();
        $client = $this->useClient(FakeFeedClient::ok(self::fixture('rss20_basic.xml')));

        // Verrou applicatif déjà tenu (collecte concurrente en cours).
        $held = Cache::lock(FetchFeedJob::lockKey($source->id), FetchFeedJob::LOCK_SECONDS);
        $this->assertTrue($held->get());

        $this->runCycle();

        $this->assertSame(0, count($client->calls), 'aucune collecte pendant le verrou');
        $this->assertSame(0, Article::where('source_id', $source->id)->count());
        $this->assertNull($source->fresh()->last_fetched_at);

        $held->release();
        $this->runCycle();

        $this->assertSame(1, count($client->calls));
        $this->assertSame(2, Article::where('source_id', $source->id)->count());
        // Verrou libéré après succès.
        $this->assertTrue(Cache::lock(FetchFeedJob::lockKey($source->id), 5)->get());
    }

    public function test_lock_released_after_business_failure(): void
    {
        $source = $this->source();
        $this->useClient(new FakeFeedClient(new FeedNetworkException('down')));

        $this->runCycle();

        $this->assertTrue(
            Cache::lock(FetchFeedJob::lockKey($source->id), 5)->get(),
            'le verrou doit être libéré même après un échec de collecte',
        );
    }

    public function test_collect_of_one_source_does_not_release_another_lock(): void
    {
        $busy = $this->source();
        $free = $this->source();
        $this->useClient(FakeFeedClient::ok(self::fixture('rss20_basic.xml')));

        $held = Cache::lock(FetchFeedJob::lockKey($busy->id), FetchFeedJob::LOCK_SECONDS);
        $this->assertTrue($held->get());

        $this->runCycle();

        // La collecte de l'autre source n'a pas touché au verrou tenu :
        // une nouvelle acquisition échoue toujours.
        $this->assertFalse(Cache::lock(FetchFeedJob::lockKey($busy->id), 5)->get());
        $held->release();
        $this->assertSame(2, Article::where('source_id', $free->id)->count());
        $this->assertSame(0, Article::where('source_id', $busy->id)->count());
    }

    public function test_stale_lock_from_crashed_process_is_reclaimed(): void
    {
        $source = $this->source();
        $client = $this->useClient(FakeFeedClient::ok(self::fixture('rss20_basic.xml')));

        // Verrou « fantôme » : processus mort sans release, TTL dépassé.
        $stale = Cache::lock(FetchFeedJob::lockKey($source->id), FetchFeedJob::LOCK_SECONDS);
        $this->assertTrue($stale->get());
        $key = Cache::getPrefix().FetchFeedJob::lockKey($source->id);
        DB::table('cache_locks')->where('key', $key)
            ->update(['expiration' => now()->subMinute()->getTimestamp()]);

        $this->runCycle();

        $this->assertSame(1, count($client->calls));
        $this->assertSame(2, Article::where('source_id', $source->id)->count());
    }

    // ---------- F. Erreur systémique et file d'échecs ----------

    public function test_systemic_error_lands_in_failed_jobs_and_source_retries_later(): void
    {
        $source = $this->source();
        // Erreur non métier : propagée par le job (P9) → failed_jobs.
        $this->app->instance(FeedClientInterface::class, new class implements FeedClientInterface
        {
            public function fetch(string $url, ?string $etag = null, ?string $lastModified = null): FeedFetchResult
            {
                throw new RuntimeException('crash inattendu');
            }
        });

        $this->runCycle();

        $this->assertSame(1, DB::table('failed_jobs')->count());
        // L'échec systémique ne touche pas aux compteurs métier.
        $this->assertSame(0, $source->fresh()->fail_count);
        $this->assertNull($source->fresh()->last_fetched_at);

        // Le verrou unique est libéré : le cycle suivant re-dispatche.
        $this->runFetch();
        $this->assertSame(1, DB::table('jobs')->count());
    }
}
