<?php

namespace Tests\Feature;

use App\Jobs\FetchFeedJob;
use App\Models\Source;
use Carbon\Carbon;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class FetchFeedsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Store de production (`database` → `cache_locks`) : la sonde du
        // verrou unique P7 est réellement exercée sur SQLite et MariaDB.
        config(['cache.default' => 'database']);
        Carbon::setTestNow(Carbon::parse('2026-11-01 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Source::unsetConnectionResolver();
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $attributes */
    private function source(array $attributes = []): Source
    {
        return Source::factory()->create($attributes);
    }

    /** Source active arrivée à échéance (intervalle normal D12). */
    private function dueSource(array $attributes = []): Source
    {
        return $this->source(array_merge(
            ['last_fetched_at' => now()->subMinutes(20), 'fail_count' => 0],
            $attributes,
        ));
    }

    // ---------- Sélection ----------

    public function test_no_sources_completes_with_zero_counters(): void
    {
        $this->artisan('feeds:fetch')
            ->expectsOutputToContain('Sources examinées : 0')
            ->expectsOutputToContain('Sources éligibles : 0')
            ->expectsOutputToContain('Jobs dispatchés : 0')
            ->expectsOutputToContain('Terminé.')
            ->assertExitCode(0)
            ->run();
    }

    public function test_never_fetched_active_source_is_dispatched(): void
    {
        Queue::fake();
        $source = $this->source();

        $this->artisan('feeds:fetch')->assertExitCode(0)->run();

        Queue::assertPushed(FetchFeedJob::class, fn (FetchFeedJob $job) => $job->sourceId === $source->id);
    }

    public function test_due_source_is_dispatched(): void
    {
        Queue::fake();
        $source = $this->dueSource();

        $this->artisan('feeds:fetch')->assertExitCode(0)->run();

        Queue::assertPushed(FetchFeedJob::class, fn (FetchFeedJob $job) => $job->sourceId === $source->id);
    }

    public function test_not_yet_due_source_is_not_dispatched(): void
    {
        Queue::fake();
        $this->source(['last_fetched_at' => now()->subMinutes(5), 'fail_count' => 0]);

        $this->artisan('feeds:fetch')
            ->expectsOutputToContain('Sources examinées : 1')
            ->expectsOutputToContain('Jobs dispatchés : 0')
            ->assertExitCode(0)
            ->run();

        Queue::assertNotPushed(FetchFeedJob::class);
    }

    public function test_inactive_source_is_not_examined(): void
    {
        Queue::fake();
        $this->source(['is_active' => false]);

        $this->artisan('feeds:fetch')
            ->expectsOutputToContain('Sources examinées : 0')
            ->assertExitCode(0)
            ->run();

        Queue::assertNotPushed(FetchFeedJob::class);
    }

    public function test_withdrawn_source_is_not_examined(): void
    {
        Queue::fake();
        $this->source(['catalogue_state' => 'withdrawn']);

        $this->artisan('feeds:fetch')
            ->expectsOutputToContain('Sources examinées : 0')
            ->assertExitCode(0)
            ->run();

        Queue::assertNotPushed(FetchFeedJob::class);
    }

    public function test_backoff_delays_next_dispatch(): void
    {
        Queue::fake();
        // fail_count=3 → délai P6 de 60 min : 30 min écoulées, pas due.
        $notDue = $this->source(['last_fetched_at' => now()->subMinutes(30), 'fail_count' => 3]);
        // Même compteur mais 70 min écoulées : due.
        $due = $this->source(['last_fetched_at' => now()->subMinutes(70), 'fail_count' => 3]);

        $this->artisan('feeds:fetch')->assertExitCode(0)->run();

        Queue::assertPushed(FetchFeedJob::class, 1);
        Queue::assertPushed(FetchFeedJob::class, fn (FetchFeedJob $job) => $job->sourceId === $due->id);
        Queue::assertNotPushed(FetchFeedJob::class, fn (FetchFeedJob $job) => $job->sourceId === $notDue->id);
    }

    public function test_deactivated_source_at_threshold_is_skipped(): void
    {
        Queue::fake();
        $source = $this->source([
            'is_active' => false,
            'fail_count' => Source::DEACTIVATION_THRESHOLD,
            'last_fetched_at' => now()->subMinutes(400),
        ]);

        $this->artisan('feeds:fetch')
            ->expectsOutputToContain('Sources examinées : 0')
            ->assertExitCode(0)
            ->run();

        Queue::assertNotPushed(FetchFeedJob::class, fn (FetchFeedJob $job) => $job->sourceId === $source->id);
    }

    public function test_mixed_states_yield_correct_counters(): void
    {
        Queue::fake();
        $this->source();                                                            // jamais collectée → due
        $this->dueSource();                                                         // due
        $this->source(['last_fetched_at' => now()->subMinutes(5)]);                 // pas due
        $this->source(['is_active' => false]);                                      // inactive
        $this->source(['catalogue_state' => 'withdrawn']);                          // retirée
        $this->source(['last_fetched_at' => now()->subMinutes(30), 'fail_count' => 3]); // backoff P6

        $this->artisan('feeds:fetch')
            ->expectsOutputToContain('Sources examinées : 4')
            ->expectsOutputToContain('Sources éligibles : 2')
            ->expectsOutputToContain('Jobs dispatchés : 2')
            ->expectsOutputToContain('Jobs déjà présents ou ignorés : 0')
            ->expectsOutputToContain('Terminé.')
            ->assertExitCode(0)
            ->run();

        Queue::assertPushed(FetchFeedJob::class, 2);
    }

    // ---------- Dispatch ----------

    public function test_dispatches_one_job_per_source_with_correct_id(): void
    {
        Queue::fake();
        $a = $this->source();
        $b = $this->dueSource();

        $this->artisan('feeds:fetch')->assertExitCode(0)->run();

        Queue::assertPushed(FetchFeedJob::class, 2);
        Queue::assertPushed(FetchFeedJob::class, fn (FetchFeedJob $job) => $job->sourceId === $a->id);
        Queue::assertPushed(FetchFeedJob::class, fn (FetchFeedJob $job) => $job->sourceId === $b->id);
    }

    public function test_already_queued_unique_job_is_counted_not_dispatched(): void
    {
        Queue::fake();
        $due = $this->dueSource();
        $free = $this->dueSource();

        // Job unique déjà posé pour $due : le cycle ne doit pas le re-dispatcher.
        (new UniqueLock(Cache::store()))->acquire(new FetchFeedJob($due->id));

        $this->artisan('feeds:fetch')
            ->expectsOutputToContain('Sources éligibles : 2')
            ->expectsOutputToContain('Jobs dispatchés : 1')
            ->expectsOutputToContain('Jobs déjà présents ou ignorés : 1')
            ->assertExitCode(0)
            ->run();

        Queue::assertPushed(FetchFeedJob::class, 1);
        Queue::assertPushed(FetchFeedJob::class, fn (FetchFeedJob $job) => $job->sourceId === $free->id);
    }

    public function test_second_cycle_is_idempotent(): void
    {
        Queue::fake();
        $this->source();
        $this->dueSource();

        $this->artisan('feeds:fetch')->assertExitCode(0)->run();
        Queue::assertPushed(FetchFeedJob::class, 2);

        // Les jobs du premier cycle restent posés : rien n'est re-dispatché.
        $this->artisan('feeds:fetch')
            ->expectsOutputToContain('Jobs dispatchés : 0')
            ->expectsOutputToContain('Jobs déjà présents ou ignorés : 2')
            ->assertExitCode(0)
            ->run();

        Queue::assertPushed(FetchFeedJob::class, 2);
    }

    public function test_real_database_queue_receives_one_row_and_second_cycle_skips(): void
    {
        // Queue `database` et verrous `cache_locks` réels (stores de
        // production) : unicité exercée de bout en bout, sans fake.
        config(['queue.default' => 'database']);
        $this->source();

        $this->artisan('feeds:fetch')->assertExitCode(0)->run();

        $this->assertSame(1, DB::table('jobs')->count());
        $payload = json_decode((string) DB::table('jobs')->value('payload'), true);
        $this->assertSame(FetchFeedJob::class, $payload['displayName'] ?? null);

        $this->artisan('feeds:fetch')
            ->expectsOutputToContain('Jobs dispatchés : 0')
            ->expectsOutputToContain('Jobs déjà présents ou ignorés : 1')
            ->assertExitCode(0)
            ->run();

        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_command_never_touches_source_metadata(): void
    {
        Queue::fake();
        $source = $this->source([
            'last_fetched_at' => now()->subMinutes(70),
            'fail_count' => 3,
            'last_error' => 'FeedTimeoutException: boom',
        ]);

        $this->artisan('feeds:fetch')->assertExitCode(0)->run();

        $fresh = $source->fresh();
        $this->assertSame(3, $fresh->fail_count);
        $this->assertTrue($source->last_fetched_at->equalTo($fresh->last_fetched_at));
        $this->assertSame('FeedTimeoutException: boom', $fresh->last_error);
        $this->assertTrue($fresh->is_active);
    }

    // ---------- Volumétrie ----------

    public function test_more_sources_than_chunk_are_all_dispatched_in_order_without_doubles(): void
    {
        Queue::fake();
        Source::factory()->count(250)->create();

        $this->artisan('feeds:fetch')->assertExitCode(0)->run();

        Queue::assertPushed(FetchFeedJob::class, 250);
        $ids = Queue::pushed(FetchFeedJob::class)->map(fn (FetchFeedJob $job) => $job->sourceId);
        $this->assertSame(250, $ids->unique()->count());
        $this->assertSame($ids->sort()->values()->all(), $ids->values()->all());
    }

    public function test_source_created_during_scan_is_collected(): void
    {
        Queue::fake();
        // Lot complet (CHUNK_SIZE) : l'insertion pendant le parcours est
        // vue par le lot suivant grâce au curseur `id > last`.
        Source::factory()->count(100)->create();

        $inserted = false;
        DB::listen(function (QueryExecuted $query) use (&$inserted) {
            if (! $inserted
                && str_starts_with($query->sql, 'select')
                && (bool) preg_match('/from [`"\[]?sources[`"\]]?/', $query->sql)) {
                $inserted = true;
                Source::factory()->create();
            }
        });

        $this->artisan('feeds:fetch')->assertExitCode(0)->run();

        $this->assertTrue($inserted);
        Queue::assertPushed(FetchFeedJob::class, 101);
    }

    // ---------- Console et erreurs ----------

    public function test_no_eligible_source_reports_cleanly(): void
    {
        Queue::fake();
        $this->source(['last_fetched_at' => now()->subMinutes(5)]);

        $this->artisan('feeds:fetch')
            ->expectsOutputToContain('Collecte des flux RSS/Atom')
            ->expectsOutputToContain('Sources examinées : 1')
            ->expectsOutputToContain('Sources éligibles : 0')
            ->expectsOutputToContain('Jobs dispatchés : 0')
            ->expectsOutputToContain('Terminé.')
            ->assertExitCode(0)
            ->run();
    }

    public function test_output_never_exposes_feed_url(): void
    {
        Queue::fake();
        $this->source(['feed_url' => 'https://secret.example.com/private-feed.xml']);

        $this->artisan('feeds:fetch')
            ->doesntExpectOutputToContain('secret.example.com')
            ->assertExitCode(0)
            ->run();
    }

    public function test_sql_error_is_logged_and_fails(): void
    {
        $log = Log::spy();
        $this->source();

        Source::setConnectionResolver(new class implements ConnectionResolverInterface
        {
            public function connection($name = null)
            {
                throw new RuntimeException('db down');
            }

            public function getDefaultConnection()
            {
                return 'default';
            }

            public function setDefaultConnection($name) {}
        });

        $this->artisan('feeds:fetch')
            ->expectsOutputToContain('Cycle interrompu')
            ->assertExitCode(1)
            ->run();

        $log->shouldHaveReceived('error')
            ->with('feeds.fetch.cycle_failed', Mockery::type('array'))
            ->once();
    }

    public function test_dispatch_error_is_logged_and_reports_partial_cycle(): void
    {
        $log = Log::spy();
        $this->dueSource();
        $this->dueSource();

        // Driver réel enregistré auprès du QueueManager : le premier push
        // passe, le second échoue — le cycle doit s'interrompre en
        // signalant son état partiel (le job déjà poussé reste géré par
        // la queue, rien à annuler).
        Queue::extend('flaky', fn () => new class implements ConnectorInterface
        {
            public function connect(array $config)
            {
                return new class implements QueueContract
                {
                    public int $pushes = 0;

                    public function size($queue = null)
                    {
                        return 0;
                    }

                    public function push($job, $data = '', $queue = null)
                    {
                        if (++$this->pushes > 1) {
                            throw new RuntimeException('queue down');
                        }

                        return null;
                    }

                    public function pushOn($queue, $job, $data = '')
                    {
                        return $this->push($job, $data, $queue);
                    }

                    public function pushRaw($payload, $queue = null, array $options = [])
                    {
                        return null;
                    }

                    public function later($delay, $job, $data = '', $queue = null)
                    {
                        return $this->push($job, $data, $queue);
                    }

                    public function laterOn($queue, $delay, $job, $data = '')
                    {
                        return $this->push($job, $data, $queue);
                    }

                    public function bulk($jobs, $data = '', $queue = null) {}

                    public function pop($queue = null)
                    {
                        return null;
                    }

                    public function getConnectionName()
                    {
                        return 'flaky';
                    }

                    public function setConnectionName($name)
                    {
                        return $this;
                    }

                    public function setContainer($container)
                    {
                        return $this;
                    }
                };
            }
        });
        config(['queue.connections.flaky' => ['driver' => 'flaky'], 'queue.default' => 'flaky']);

        $this->artisan('feeds:fetch')
            ->expectsOutputToContain('Cycle interrompu')
            ->expectsOutputToContain('Jobs dispatchés : 1')
            ->assertExitCode(1)
            ->run();

        $log->shouldHaveReceived('error')
            ->with('feeds.fetch.cycle_failed', Mockery::on(
                fn (array $context) => ($context['dispatched'] ?? null) === 1
            ))
            ->once();
    }
}
