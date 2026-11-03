<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class FeedCollectorScheduleTest extends TestCase
{
    /** @return array<int, Event> */
    private function scheduledEvents(): array
    {
        // Charge routes/console.php comme le fait le kernel console
        // réel, puis lit les événements enregistrés sur le Schedule.
        $this->app->make(Kernel::class)->bootstrap();

        return app(Schedule::class)->events();
    }

    private function findEvent(string $needle): ?Event
    {
        return collect($this->scheduledEvents())
            ->first(fn (Event $event) => str_contains($event->command, $needle));
    }

    public function test_feeds_fetch_is_scheduled_every_five_minutes_without_overlap(): void
    {
        $event = $this->findEvent('feeds:fetch');

        $this->assertNotNull($event, 'feeds:fetch absent du scheduler');
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(15, $event->expiresAt);
    }

    public function test_queue_work_is_scheduled_every_minute_without_overlap(): void
    {
        $event = $this->findEvent('queue:work');

        $this->assertNotNull($event, 'queue:work absent du scheduler');
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(10, $event->expiresAt);
    }

    public function test_queue_work_is_called_with_drain_arguments(): void
    {
        $event = $this->findEvent('queue:work');

        $this->assertNotNull($event, 'queue:work absent du scheduler');
        $this->assertStringContainsString(
            'queue:work --stop-when-empty --max-time=50',
            $event->command,
        );
    }

    public function test_alerts_generate_daily_at_0630_paris_time(): void
    {
        $event = $this->findEvent('alerts:generate');

        $this->assertNotNull($event, 'alerts:generate absent du scheduler');
        $this->assertSame('30 6 * * *', $event->expression);
        $this->assertSame('Europe/Paris', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_alerts_distribute_every_five_minutes(): void
    {
        $event = $this->findEvent('alerts:distribute');

        $this->assertNotNull($event, 'alerts:distribute absent du scheduler');
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_alerts_sweep_every_ten_minutes(): void
    {
        $event = $this->findEvent('alerts:sweep');

        $this->assertNotNull($event, 'alerts:sweep absent du scheduler');
        $this->assertSame('*/10 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_only_the_expected_tasks_are_scheduled(): void
    {
        // feeds:fetch, queue:work, alerts:generate/distribute/sweep.
        $this->assertCount(5, $this->scheduledEvents());
    }

    public function test_schedule_list_shows_both_tasks(): void
    {
        Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertStringContainsString('feeds:fetch', $output);
        $this->assertStringContainsString('queue:work --stop-when-empty --max-time=50', $output);
        $this->assertStringContainsString('alerts:generate', $output);
        $this->assertStringContainsString('alerts:distribute', $output);
        $this->assertStringContainsString('alerts:sweep', $output);
    }
}
