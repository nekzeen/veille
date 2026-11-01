<?php

namespace Tests\Feature;

use App\Models\Source;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SourceFetchScheduleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-01 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, array{int, int}> */
    public static function backoffProvider(): array
    {
        return [
            'aucun échec' => [0, 15],
            '1 échec' => [1, 15],
            '2 échecs' => [2, 30],
            '3 échecs' => [3, 60],
            '4 échecs' => [4, 120],
            '5 échecs' => [5, 240],
            '6 échecs' => [6, 360],
            '7 échecs' => [7, 360],
            '10 échecs' => [10, 360],
            'max smallint' => [65535, 360],
        ];
    }

    #[DataProvider('backoffProvider')]
    public function test_backoff_minutes_follow_p6(int $failCount, int $minutes): void
    {
        $this->assertSame($minutes, Source::backoffMinutes($failCount));
    }

    public function test_never_fetched_source_is_due_immediately(): void
    {
        $source = new Source(['is_active' => true]);

        $this->assertNull($source->dueAt());
        $this->assertTrue($source->isDue());
    }

    public function test_after_success_due_at_is_normal_interval(): void
    {
        $source = (new Source)->forceFill(['last_fetched_at' => now()->subMinutes(10), 'fail_count' => 0]);

        $this->assertTrue($source->dueAt()->equalTo(now()->addMinutes(5)));
        $this->assertFalse($source->isDue());
        $this->assertTrue($source->isDue(now()->addMinutes(5)));
    }

    public function test_after_failures_due_at_applies_backoff(): void
    {
        $source = (new Source)->forceFill(['last_fetched_at' => now(), 'fail_count' => 3]);

        $this->assertTrue($source->dueAt()->equalTo(now()->addMinutes(60)));
        $this->assertFalse($source->isDue(now()->addMinutes(59)));
        $this->assertTrue($source->isDue(now()->addMinutes(60)));
    }

    public function test_eligibility_requires_active_and_not_orphaned(): void
    {
        $this->assertTrue((new Source)->forceFill(['is_active' => true, 'orphaned_at' => null])->isEligibleForFetch());
        $this->assertFalse((new Source)->forceFill(['is_active' => false, 'orphaned_at' => null])->isEligibleForFetch());
        $this->assertFalse((new Source)->forceFill(['is_active' => true, 'orphaned_at' => now()])->isEligibleForFetch());
    }
}
