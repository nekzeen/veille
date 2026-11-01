<?php

namespace Tests\Feature;

use App\Jobs\FetchFeedJob;
use App\Models\Source;
use App\Services\Feed\Data\FeedFetchResult;
use App\Services\Feed\Exceptions\FeedTimeoutException;
use App\Services\Feed\FeedClientInterface;
use Carbon\Carbon;
use Illuminate\Bus\UniqueLock;
use Illuminate\Cache\DatabaseLock;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\Fakes\FakeFeedClient;
use Tests\TestCase;

/**
 * Verrous de FetchFeedJob sur le store `database` (table `cache_locks`)
 * de la connexion de test : MariaDB avec phpunit.mariadb.xml, SQLite
 * avec phpunit.xml. Configuration surchargée en mémoire uniquement.
 */
class FetchFeedJobDatabaseLockTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE_ID = 42;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'database', 'queue.default' => 'database']);
        Carbon::setTestNow(Carbon::parse('2026-11-01 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function dbStore(): DatabaseStore
    {
        $store = Cache::store('database')->getStore();
        if (! $store instanceof DatabaseStore) {
            $this->fail('Store database attendu, obtenu '.$store::class);
        }

        return $store;
    }

    private function lock(int $sourceId, ?string $owner = null): DatabaseLock
    {
        $lock = $this->dbStore()->lock(FetchFeedJob::lockKey($sourceId), FetchFeedJob::LOCK_SECONDS, $owner);
        if (! $lock instanceof DatabaseLock) {
            $this->fail('Verrou database attendu, obtenu '.$lock::class);
        }

        return $lock;
    }

    private function lockColumn(string $name, string $column): mixed
    {
        return DB::table('cache_locks')->where('key', $this->dbStore()->getPrefix().$name)->value($column);
    }

    private function ownerOf(int $sourceId): mixed
    {
        return $this->lockColumn(FetchFeedJob::lockKey($sourceId), 'owner');
    }

    private function uniqueKey(int $sourceId): string
    {
        return UniqueLock::getKey(new FetchFeedJob($sourceId));
    }

    private function work(FeedClientInterface $client): void
    {
        $this->app->instance(FeedClientInterface::class, $client);
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true]);
    }

    // ---------- Store réellement utilisé ----------

    public function test_locks_use_database_store_on_test_connection(): void
    {
        $connection = DB::connection();

        $this->assertInstanceOf(DatabaseStore::class, Cache::getStore());
        $this->assertNull(config('cache.stores.database.lock_connection'));
        $this->assertSame(config('database.default'), $connection->getName());
        // Garde-fou : jamais la base de production.
        $this->assertNotSame('veille_db', $connection->getDatabaseName());

        if ($connection->getDriverName() === 'mariadb') {
            $this->assertSame('veille_test', $connection->getDatabaseName());
            $this->assertStringContainsString('MariaDB', (string) $connection->scalar('select version()'));
        } else {
            $this->assertSame('sqlite', $connection->getDriverName());
            $this->assertSame(':memory:', $connection->getDatabaseName());
        }

        $this->assertTrue($this->lock(self::SOURCE_ID)->get());
        $this->assertSame(1, DB::table('cache_locks')->count());
    }

    // ---------- Verrou complémentaire ----------

    public function test_owner_acquires_lock_for_900_seconds(): void
    {
        $lock = $this->lock(self::SOURCE_ID);

        $this->assertTrue($lock->get());
        $this->assertSame($lock->owner(), $this->ownerOf(self::SOURCE_ID));
        $this->assertSame(
            now()->getTimestamp() + 900,
            (int) $this->lockColumn(FetchFeedJob::lockKey(self::SOURCE_ID), 'expiration'),
        );
    }

    public function test_second_acquisition_with_same_key_is_refused(): void
    {
        $first = $this->lock(self::SOURCE_ID);
        $this->assertTrue($first->get());

        $this->assertFalse($this->lock(self::SOURCE_ID)->get());
        $this->assertSame($first->owner(), $this->ownerOf(self::SOURCE_ID));
        // Clé propre à chaque source.
        $this->assertTrue($this->lock(self::SOURCE_ID + 1)->get());
    }

    public function test_owner_releases_lock(): void
    {
        $lock = $this->lock(self::SOURCE_ID);
        $this->assertTrue($lock->get());

        $this->assertTrue($lock->release());

        $this->assertNull($this->ownerOf(self::SOURCE_ID));
        $this->assertSame(0, DB::table('cache_locks')->count());
    }

    public function test_lock_can_be_acquired_again_after_release(): void
    {
        $first = $this->lock(self::SOURCE_ID);
        $this->assertTrue($first->get());
        $this->assertTrue($first->release());

        $next = $this->lock(self::SOURCE_ID);

        $this->assertTrue($next->get());
        $this->assertSame($next->owner(), $this->ownerOf(self::SOURCE_ID));
    }

    public function test_other_owner_cannot_release_lock(): void
    {
        $owner = $this->lock(self::SOURCE_ID);
        $this->assertTrue($owner->get());

        $this->assertFalse($this->lock(self::SOURCE_ID)->release());
        $this->assertFalse($this->lock(self::SOURCE_ID, 'autre-proprietaire')->release());

        $this->assertSame($owner->owner(), $this->ownerOf(self::SOURCE_ID));
        $this->assertTrue($owner->isOwnedByCurrentProcess());
    }

    public function test_lock_expires_after_900_seconds(): void
    {
        $first = $this->lock(self::SOURCE_ID);
        $this->assertTrue($first->get());

        $this->travel(899)->seconds();
        $this->assertFalse($this->lock(self::SOURCE_ID)->get());

        $this->travel(1)->seconds();
        $successor = $this->lock(self::SOURCE_ID);
        $this->assertTrue($successor->get());
        $this->assertSame($successor->owner(), $this->ownerOf(self::SOURCE_ID));

        // L'ancien propriétaire ne peut plus supprimer le verrou repris.
        $this->assertFalse($first->release());
        $this->assertSame($successor->owner(), $this->ownerOf(self::SOURCE_ID));
    }

    public function test_released_and_expired_locks_leave_no_row(): void
    {
        $released = $this->lock(self::SOURCE_ID);
        $this->assertTrue($released->get());
        $this->assertTrue($released->release());
        $this->assertSame(0, DB::table('cache_locks')->count());

        // Propriétaire disparu sans libération (worker tué).
        $this->assertTrue($this->lock(self::SOURCE_ID + 1)->get());
        $this->travel(FetchFeedJob::LOCK_SECONDS)->seconds();
        $this->lock(self::SOURCE_ID + 2)->pruneExpiredLocks();

        $this->assertSame(0, DB::table('cache_locks')->count());
    }

    // ---------- Cycle de vie via la file database et un worker ----------

    public function test_unique_lock_and_complementary_lock_lifecycle_on_success(): void
    {
        $source = Source::factory()->create();
        $probe = new class(FakeFeedClient::notModified()) implements FeedClientInterface
        {
            public array $locksDuringFetch = [];

            public function __construct(private readonly FakeFeedClient $inner) {}

            public function fetch(string $url, ?string $etag = null, ?string $lastModified = null): FeedFetchResult
            {
                $this->locksDuringFetch = DB::table('cache_locks')->pluck('owner', 'key')->all();

                return $this->inner->fetch($url, $etag, $lastModified);
            }
        };

        FetchFeedJob::dispatch($source->id);
        FetchFeedJob::dispatch($source->id);

        // ShouldBeUnique : second dispatch refusé, verrou natif de 900 s.
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertNotNull($this->lockColumn($this->uniqueKey($source->id), 'owner'));
        $this->assertSame(now()->getTimestamp() + 900, (int) $this->lockColumn($this->uniqueKey($source->id), 'expiration'));

        $this->work($probe);

        // Pendant le fetch : les deux verrous sont tenus.
        $prefix = $this->dbStore()->getPrefix();
        $this->assertEqualsCanonicalizing(
            [$prefix.$this->uniqueKey($source->id), $prefix.FetchFeedJob::lockKey($source->id)],
            array_keys($probe->locksDuringFetch),
        );

        // Après : file vide, aucun échec, plus aucun verrou.
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('cache_locks')->count());
        $this->assertNotNull($source->fresh()->last_success_at);

        FetchFeedJob::dispatch($source->id);
        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_business_failure_releases_both_locks_without_failed_job(): void
    {
        $source = Source::factory()->create();
        FetchFeedJob::dispatch($source->id);

        $this->work(new FakeFeedClient(new FeedTimeoutException('délai dépassé')));

        $this->assertSame(1, $source->fresh()->fail_count);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('cache_locks')->count());
    }

    public function test_systemic_exception_fails_job_and_releases_both_locks(): void
    {
        $log = Log::spy();
        $source = Source::factory()->create();
        $client = new FakeFeedClient(new RuntimeException('panne imprévue'));
        FetchFeedJob::dispatch($source->id);

        $this->work($client);

        $this->assertCount(1, $client->calls);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('cache_locks')->count());

        $fresh = $source->fresh();
        $this->assertSame(0, $fresh->fail_count);
        $this->assertNull($fresh->last_fetched_at);
        $this->assertNull($fresh->last_error);
        $log->shouldHaveReceived('error')->with('feeds.fetch.job_failed', [
            'source_id' => $source->id,
            'exception_class' => RuntimeException::class,
        ]);

        FetchFeedJob::dispatch($source->id);
        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_job_skips_when_complementary_lock_is_held_elsewhere(): void
    {
        $source = Source::factory()->create();
        $holder = $this->lock($source->id, 'autre-worker');
        $this->assertTrue($holder->get());
        $client = FakeFeedClient::notModified();
        FetchFeedJob::dispatch($source->id);

        $this->work($client);

        $this->assertSame([], $client->calls);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertNull($this->lockColumn($this->uniqueKey($source->id), 'owner'));
        $this->assertSame('autre-worker', $this->ownerOf($source->id));
        $this->assertSame(1, DB::table('cache_locks')->count());

        $this->assertTrue($holder->release());
        $this->assertSame(0, DB::table('cache_locks')->count());
    }
}
