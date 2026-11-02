<?php

namespace Tests\Feature;

use App\Jobs\FetchFeedJob;
use App\Models\Article;
use App\Models\Source;
use App\Services\Feed\ArticleUpsertService;
use App\Services\Feed\Exceptions\FeedDnsException;
use App\Services\Feed\Exceptions\FeedHttpException;
use App\Services\Feed\Exceptions\FeedNetworkException;
use App\Services\Feed\Exceptions\FeedRedirectException;
use App\Services\Feed\Exceptions\FeedTimeoutException;
use App\Services\Feed\Exceptions\FeedTlsException;
use App\Services\Feed\Exceptions\FeedTooLargeException;
use App\Services\Feed\Exceptions\FeedUrlException;
use App\Services\Feed\FeedClientInterface;
use App\Services\Feed\FeedParserInterface;
use App\Services\KeywordMatcher;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fakes\FakeFeedClient;
use Tests\Fakes\SpyFeedParser;
use Tests\TestCase;
use Throwable;

class FetchFeedJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Store de production (`database` → `cache_locks`) : verrous
        // réellement exercés sur SQLite et MariaDB.
        config(['cache.default' => 'database']);
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

    private function runJob(
        Source|int $source,
        FeedClientInterface $client,
        ?FeedParserInterface $parser = null,
    ): void {
        $id = $source instanceof Source ? $source->id : $source;
        (new FetchFeedJob($id))->handle($client, $parser ?? new SpyFeedParser, app(ArticleUpsertService::class), app(KeywordMatcher::class));
    }

    private function assertUntouched(Source $source): void
    {
        $fresh = $source->fresh();
        $this->assertSame($source->fail_count ?? 0, $fresh->fail_count);
        $this->assertEquals($source->last_fetched_at, $fresh->last_fetched_at);
        $this->assertNull($fresh->last_success_at);
        $this->assertNull($fresh->last_error);
    }

    // ---------- Configuration du job ----------

    public function test_job_configuration_matches_p7(): void
    {
        $job = new FetchFeedJob(42);

        $this->assertSame(1, $job->tries);
        $this->assertSame(60, $job->timeout);
        $this->assertSame(900, $job->uniqueFor);
        $this->assertSame('42', $job->uniqueId());
        $this->assertSame(42, unserialize(serialize($job))->sourceId);
    }

    public function test_job_is_unique_per_source(): void
    {
        Queue::fake();

        FetchFeedJob::dispatch(1);
        FetchFeedJob::dispatch(1);
        FetchFeedJob::dispatch(2);

        Queue::assertPushed(FetchFeedJob::class, 2);
        Queue::assertPushed(FetchFeedJob::class, fn (FetchFeedJob $job) => $job->sourceId === 1);
        Queue::assertPushed(FetchFeedJob::class, fn (FetchFeedJob $job) => $job->sourceId === 2);
    }

    public function test_container_resolves_handle_dependencies(): void
    {
        $source = $this->source(['is_active' => false]);

        app()->call([new FetchFeedJob($source->id), 'handle']);

        $this->assertUntouched($source);
    }

    // ---------- Éligibilité ----------

    public function test_missing_source_is_ignored(): void
    {
        $client = FakeFeedClient::ok(self::fixture('rss20_basic.xml'));

        $this->runJob(999_999, $client);

        $this->assertSame([], $client->calls);
    }

    public function test_inactive_source_is_ignored(): void
    {
        $source = $this->source(['is_active' => false]);
        $client = FakeFeedClient::ok(self::fixture('rss20_basic.xml'));

        $this->runJob($source, $client);

        $this->assertSame([], $client->calls);
        $this->assertUntouched($source);
    }

    public function test_orphaned_source_is_ignored(): void
    {
        $source = $this->source(['orphaned_at' => now()->subDay()]);
        $client = FakeFeedClient::ok(self::fixture('rss20_basic.xml'));

        $this->runJob($source, $client);

        $this->assertSame([], $client->calls);
        $this->assertUntouched($source);
    }

    public function test_never_fetched_source_is_collected(): void
    {
        $source = $this->source(['last_fetched_at' => null]);
        $client = FakeFeedClient::ok(self::fixture('rss20_basic.xml'));

        $this->runJob($source, $client);

        $this->assertCount(1, $client->calls);
        $this->assertSame($source->feed_url, $client->calls[0]['url']);
    }

    public function test_due_source_is_collected(): void
    {
        $source = $this->source(['last_fetched_at' => now()->subMinutes(15)]);
        $client = FakeFeedClient::notModified();

        $this->runJob($source, $client);

        $this->assertCount(1, $client->calls);
    }

    public function test_source_not_yet_due_is_ignored(): void
    {
        $source = $this->source(['last_fetched_at' => now()->subMinutes(14)]);
        $client = FakeFeedClient::notModified();

        $this->runJob($source, $client);

        $this->assertSame([], $client->calls);
        $this->assertUntouched($source);
    }

    public function test_source_in_backoff_is_ignored(): void
    {
        $source = $this->source(['fail_count' => 3, 'last_fetched_at' => now()->subMinutes(59)]);
        $client = FakeFeedClient::notModified();

        $this->runJob($source, $client);

        $this->assertSame([], $client->calls);
        $this->assertSame(3, $source->fresh()->fail_count);
    }

    public function test_manually_reactivated_source_is_collected_once_backoff_elapsed(): void
    {
        $source = $this->source([
            'is_active' => true,
            'fail_count' => 10,
            'last_fetched_at' => now()->subMinutes(360),
        ]);
        $client = FakeFeedClient::notModified();

        $this->runJob($source, $client);

        $this->assertCount(1, $client->calls);
        $fresh = $source->fresh();
        $this->assertSame(0, $fresh->fail_count);
        $this->assertTrue($fresh->is_active);
    }

    public function test_manually_reactivated_source_keeps_backoff_until_elapsed(): void
    {
        $source = $this->source([
            'is_active' => true,
            'fail_count' => 10,
            'last_fetched_at' => now()->subMinutes(359),
        ]);
        $client = FakeFeedClient::notModified();

        $this->runJob($source, $client);

        $this->assertSame([], $client->calls);
    }

    // ---------- Verrous ----------

    public function test_lock_is_released_after_success(): void
    {
        $source = $this->source();

        $this->runJob($source, FakeFeedClient::ok(self::fixture('rss20_basic.xml')));

        $this->assertTrue(Cache::lock(FetchFeedJob::lockKey($source->id), 10)->get());
    }

    public function test_lock_held_elsewhere_skips_without_side_effects_or_release(): void
    {
        $log = Log::spy();
        $source = $this->source();
        $held = Cache::lock(FetchFeedJob::lockKey($source->id), FetchFeedJob::LOCK_SECONDS);
        $this->assertTrue($held->get());
        $client = FakeFeedClient::ok(self::fixture('rss20_basic.xml'));

        $this->runJob($source, $client);

        $this->assertSame([], $client->calls);
        $this->assertUntouched($source);
        $this->assertSame(0, Article::count());
        // Le job non propriétaire n'a pas libéré le verrou.
        $this->assertFalse(Cache::lock(FetchFeedJob::lockKey($source->id), 10)->get());
        $this->assertTrue($held->release());
        $log->shouldHaveReceived('info')->with('feeds.fetch.skipped', ['source_id' => $source->id, 'reason' => 'lock_held']);
    }

    public function test_lock_is_per_source(): void
    {
        $locked = $this->source();
        $free = $this->source();
        Cache::lock(FetchFeedJob::lockKey($locked->id), FetchFeedJob::LOCK_SECONDS)->get();
        $client = FakeFeedClient::notModified();

        $this->runJob($free, $client);

        $this->assertCount(1, $client->calls);
    }

    public function test_lock_is_released_after_systemic_exception(): void
    {
        $source = $this->source();
        $client = new FakeFeedClient(new \RuntimeException('panne imprévue'));

        try {
            $this->runJob($source, $client);
            $this->fail('Exception attendue.');
        } catch (\RuntimeException $e) {
            $this->assertSame('panne imprévue', $e->getMessage());
        }

        $this->assertTrue(Cache::lock(FetchFeedJob::lockKey($source->id), 10)->get());
        $this->assertUntouched($source);
    }

    // ---------- HTTP 200 ----------

    public function test_http_200_persists_articles_and_resets_counter(): void
    {
        $log = Log::spy();
        $source = $this->source([
            'fail_count' => 3,
            'last_fetched_at' => now()->subMinutes(60),
            'last_error' => 'FeedTimeoutException: délai',
            'etag' => '"old"',
            'last_modified' => 'Sat, 31 Oct 2026 10:00:00 GMT',
        ]);
        $client = FakeFeedClient::ok(self::fixture('rss20_basic.xml'), '"new"', 'Sun, 01 Nov 2026 11:00:00 GMT');

        $this->runJob($source, $client);

        $this->assertSame([['url' => $source->feed_url, 'etag' => '"old"', 'lastModified' => 'Sat, 31 Oct 2026 10:00:00 GMT']], $client->calls);
        $this->assertSame(2, Article::where('source_id', $source->id)->count());

        $fresh = $source->fresh();
        $this->assertSame(0, $fresh->fail_count);
        $this->assertNull($fresh->last_error);
        $this->assertTrue($fresh->last_fetched_at->equalTo(now()));
        $this->assertTrue($fresh->last_success_at->equalTo(now()));
        $this->assertSame('"new"', $fresh->etag);
        $this->assertSame('Sun, 01 Nov 2026 11:00:00 GMT', $fresh->last_modified);
        $this->assertTrue($fresh->is_active);

        $log->shouldHaveReceived('info')->with('feeds.fetch.succeeded', Mockery::on(
            fn (array $c): bool => $c['source_id'] === $source->id
                && $c['http_status'] === 200
                && $c['received'] === 2
                && $c['created'] === 2
                && $c['fail_count'] === 0
                && $c['next_due_at'] === now()->addMinutes(15)->toIso8601String()
        ));
    }

    public function test_http_200_keeps_existing_validators_when_absent(): void
    {
        $source = $this->source(['etag' => '"old"', 'last_modified' => 'Sat, 31 Oct 2026 10:00:00 GMT']);

        $this->runJob($source, FakeFeedClient::ok(self::fixture('rss20_basic.xml')));

        $fresh = $source->fresh();
        $this->assertSame('"old"', $fresh->etag);
        $this->assertSame('Sat, 31 Oct 2026 10:00:00 GMT', $fresh->last_modified);
    }

    public function test_http_200_fills_only_empty_descriptive_metadata(): void
    {
        $empty = $this->source(['name' => '', 'site_url' => null, 'description' => null]);
        $filled = $this->source(['name' => 'Mon nom', 'site_url' => 'https://perso.fr', 'description' => 'Ma description']);

        $this->runJob($empty, FakeFeedClient::ok(self::fixture('rss20_basic.xml')));
        $this->runJob($filled, FakeFeedClient::ok(self::fixture('rss20_basic.xml')));

        $empty->refresh();
        $this->assertSame("Flux d'exemple", $empty->name);
        $this->assertSame('https://www.exemple.fr/', $empty->site_url);
        $this->assertSame('Le flux de démonstration', $empty->description);

        $filled->refresh();
        $this->assertSame('Mon nom', $filled->name);
        $this->assertSame('https://perso.fr', $filled->site_url);
        $this->assertSame('Ma description', $filled->description);
    }

    // ---------- HTTP 304 ----------

    public function test_http_304_skips_parsing_and_persistence_and_resets_counter(): void
    {
        $source = $this->source([
            'fail_count' => 2,
            'last_fetched_at' => now()->subMinutes(30),
            'last_error' => 'FeedHttpException: Réponse HTTP 503.',
            'etag' => '"same"',
            'last_modified' => 'Sat, 31 Oct 2026 10:00:00 GMT',
        ]);
        $parser = new SpyFeedParser;

        $this->runJob($source, FakeFeedClient::notModified(), $parser);

        $this->assertSame(0, $parser->calls);
        $this->assertSame(0, Article::count());
        $fresh = $source->fresh();
        $this->assertSame(0, $fresh->fail_count);
        $this->assertNull($fresh->last_error);
        $this->assertTrue($fresh->last_fetched_at->equalTo(now()));
        $this->assertTrue($fresh->last_success_at->equalTo(now()));
        $this->assertSame('"same"', $fresh->etag);
        $this->assertSame('Sat, 31 Oct 2026 10:00:00 GMT', $fresh->last_modified);
    }

    public function test_http_304_stores_validators_when_provided(): void
    {
        $source = $this->source(['etag' => '"v1"', 'last_modified' => 'Sat, 31 Oct 2026 10:00:00 GMT']);

        $this->runJob($source, FakeFeedClient::notModified('"v2"', 'Sun, 01 Nov 2026 11:00:00 GMT'));

        $fresh = $source->fresh();
        $this->assertSame('"v2"', $fresh->etag);
        $this->assertSame('Sun, 01 Nov 2026 11:00:00 GMT', $fresh->last_modified);
    }

    // ---------- Échecs de collecte ----------

    /** @return array<string, array{Throwable, string, ?int}> */
    public static function fetchFailureProvider(): array
    {
        return [
            'HTTP' => [new FeedHttpException(503), 'FeedHttpException', 503],
            'DNS' => [new FeedDnsException('Aucune adresse IP.'), 'FeedDnsException', null],
            'TLS' => [new FeedTlsException('Erreur TLS.'), 'FeedTlsException', null],
            'réseau' => [new FeedNetworkException('Connexion refusée.'), 'FeedNetworkException', null],
            'timeout' => [new FeedTimeoutException('Délai dépassé.'), 'FeedTimeoutException', null],
            'trop volumineux' => [new FeedTooLargeException('> 5 Mio.'), 'FeedTooLargeException', null],
            'redirection' => [new FeedRedirectException('Redirection refusée.'), 'FeedRedirectException', null],
            'URL' => [new FeedUrlException('Port refusé.'), 'FeedUrlException', null],
        ];
    }

    #[DataProvider('fetchFailureProvider')]
    public function test_fetch_failure_is_recorded_without_propagation(Throwable $error, string $class, ?int $status): void
    {
        $log = Log::spy();
        $source = $this->source();

        $this->runJob($source, new FakeFeedClient($error));

        $fresh = $source->fresh();
        $this->assertSame(1, $fresh->fail_count);
        $this->assertTrue($fresh->last_fetched_at->equalTo(now()));
        $this->assertNull($fresh->last_success_at);
        $this->assertStringStartsWith($class.': ', $fresh->last_error);
        $this->assertTrue($fresh->is_active);
        $this->assertTrue(Cache::lock(FetchFeedJob::lockKey($source->id), 10)->get());

        $log->shouldHaveReceived('warning')->with('feeds.fetch.failed', Mockery::on(
            fn (array $c): bool => $c['source_id'] === $source->id
                && $c['error_class'] === $class
                && $c['http_status'] === $status
                && $c['fail_count'] === 1
                && $c['next_due_at'] === now()->addMinutes(15)->toIso8601String()
        ));
    }

    public function test_parse_failure_is_recorded_without_persistence(): void
    {
        $source = $this->source();

        $this->runJob($source, FakeFeedClient::ok(self::fixture('invalid_malformed.xml'), '"new"'));

        $fresh = $source->fresh();
        $this->assertSame(1, $fresh->fail_count);
        $this->assertStringStartsWith('FeedParseException: ', $fresh->last_error);
        $this->assertNull($fresh->last_success_at);
        $this->assertNull($fresh->etag);
        $this->assertSame(0, Article::count());
    }

    public function test_last_error_is_truncated_to_column_size(): void
    {
        $source = $this->source();

        $this->runJob($source, new FakeFeedClient(new FeedNetworkException(str_repeat('é', 800))));

        $this->assertSame(500, mb_strlen($source->fresh()->last_error));
    }

    // ---------- Backoff et seuil ----------

    /** @return array<string, array{int, int}> */
    public static function backoffProvider(): array
    {
        return [
            '1 échec' => [1, 15],
            '2 échecs' => [2, 30],
            '3 échecs' => [3, 60],
            '4 échecs' => [4, 120],
            '5 échecs' => [5, 240],
            '6 échecs' => [6, 360],
        ];
    }

    #[DataProvider('backoffProvider')]
    public function test_failure_applies_backoff(int $failCount, int $minutes): void
    {
        $source = $this->source(['fail_count' => $failCount - 1, 'last_fetched_at' => now()->subDay()]);

        $this->runJob($source, new FakeFeedClient(new FeedTimeoutException('Délai dépassé.')));

        $fresh = $source->fresh();
        $this->assertSame($failCount, $fresh->fail_count);
        $this->assertTrue($fresh->last_fetched_at->equalTo(now()));
        $this->assertTrue($fresh->dueAt()->equalTo(now()->addMinutes($minutes)));
        $this->assertFalse($fresh->isDue(now()->addMinutes($minutes)->subSecond()));
        $this->assertTrue($fresh->isDue(now()->addMinutes($minutes)));
        $this->assertTrue($fresh->is_active);
    }

    public function test_ninth_failure_keeps_source_active(): void
    {
        $source = $this->source(['fail_count' => 8, 'last_fetched_at' => now()->subDay()]);

        $this->runJob($source, new FakeFeedClient(new FeedHttpException(500)));

        $fresh = $source->fresh();
        $this->assertSame(9, $fresh->fail_count);
        $this->assertTrue($fresh->is_active);
    }

    public function test_tenth_failure_deactivates_source(): void
    {
        $log = Log::spy();
        $source = $this->source(['fail_count' => 9, 'last_fetched_at' => now()->subDay()]);

        $this->runJob($source, new FakeFeedClient(new FeedHttpException(500)));

        $fresh = $source->fresh();
        $this->assertSame(10, $fresh->fail_count);
        $this->assertFalse($fresh->is_active);
        $log->shouldHaveReceived('warning')->with('feeds.fetch.source_deactivated', [
            'source_id' => $source->id,
            'fail_count' => 10,
            'reason' => 'consecutive_failures_threshold',
        ]);
        $log->shouldHaveReceived('warning')->with('feeds.fetch.failed', Mockery::on(
            fn (array $c): bool => $c['fail_count'] === 10 && $c['next_due_at'] === null
        ));
    }

    // ---------- Erreurs systémiques / atomicité P9 ----------

    public function test_systemic_persistence_error_rolls_back_and_propagates(): void
    {
        $source = $this->source(['fail_count' => 2, 'last_fetched_at' => now()->subMinutes(30)]);
        $lastFetchedAt = $source->last_fetched_at;
        $created = 0;
        Article::creating(function () use (&$created): void {
            if (++$created === 2) {
                throw new QueryException('testing', 'insert into articles', [], new \PDOException('panne simulée'));
            }
        });

        try {
            $this->runJob($source, FakeFeedClient::ok(self::fixture('rss20_basic.xml'), '"new"'));
            $this->fail('QueryException attendue.');
        } catch (QueryException) {
            // propagée vers failed_jobs
        } finally {
            Article::flushEventListeners();
        }

        $this->assertSame(2, $created);
        $this->assertSame(0, Article::count());
        $fresh = $source->fresh();
        $this->assertSame(2, $fresh->fail_count);
        $this->assertTrue($fresh->last_fetched_at->equalTo($lastFetchedAt));
        $this->assertNull($fresh->last_success_at);
        $this->assertNull($fresh->last_error);
        $this->assertNull($fresh->etag);
        $this->assertTrue(Cache::lock(FetchFeedJob::lockKey($source->id), 10)->get());
    }

    public function test_failed_hook_logs_without_touching_counters(): void
    {
        $log = Log::spy();
        $source = $this->source(['fail_count' => 2]);

        (new FetchFeedJob($source->id))->failed(new \RuntimeException('panne'));

        $this->assertSame(2, $source->fresh()->fail_count);
        $log->shouldHaveReceived('error')->with('feeds.fetch.job_failed', [
            'source_id' => $source->id,
            'exception_class' => \RuntimeException::class,
        ]);
    }
}
