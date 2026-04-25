<?php

namespace Tests\Feature\Broadcasting;

use App\Events\Mobile\ActionProgressUpdated;
use App\Events\Mobile\AnomalyRaised;
use App\Events\Mobile\NewEventBroadcast;
use App\Events\Mobile\NotificationReceived;
use App\Models\ActionProgress;
use App\Models\Event as EventModel;
use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use App\Models\User;
use App\Notifications\SystemMaintenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BroadcastEventsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function action_progress_dispatches_broadcast_on_save(): void
    {
        Event::fake([ActionProgressUpdated::class]);

        $progress = $this->makeActionProgress();

        Event::assertDispatched(
            ActionProgressUpdated::class,
            fn (ActionProgressUpdated $e) => $e->actionProgressId === (string) $progress->id
                && $e->userId === (string) $progress->user_id,
        );
    }

    #[Test]
    public function action_progress_broadcasts_again_on_update(): void
    {
        Event::fake([ActionProgressUpdated::class]);

        $progress = $this->makeActionProgress();
        $progress->update(['progress' => 75, 'message' => 'Almost there']);

        Event::assertDispatchedTimes(ActionProgressUpdated::class, 2);
    }

    #[Test]
    public function metric_trend_anomaly_dispatches_broadcast(): void
    {
        Event::fake([AnomalyRaised::class]);

        $stat = MetricStatistic::factory()->create();
        $trend = MetricTrend::factory()->create([
            'metric_statistic_id' => $stat->id,
            'type' => 'anomaly_high',
        ]);

        Event::assertDispatched(
            AnomalyRaised::class,
            fn (AnomalyRaised $e) => $e->metricTrendId === (string) $trend->id
                && $e->userId === (string) $stat->user_id
                && $e->type === 'anomaly_high',
        );
    }

    #[Test]
    public function metric_trend_non_anomaly_does_not_broadcast(): void
    {
        Event::fake([AnomalyRaised::class]);

        $stat = MetricStatistic::factory()->create();
        MetricTrend::factory()->create([
            'metric_statistic_id' => $stat->id,
            'type' => 'trend_up_weekly',
        ]);

        Event::assertNotDispatched(AnomalyRaised::class);
    }

    #[Test]
    public function event_creation_dispatches_broadcast_when_lock_is_acquired(): void
    {
        Event::fake([NewEventBroadcast::class]);
        Redis::shouldReceive('set')
            ->once()
            ->withArgs(fn ($key, $val, $modeEx, $ttl, $modeNx) => str_starts_with($key, 'broadcast:newevent:')
                && $val === '1' && $modeEx === 'EX' && $ttl === 2 && $modeNx === 'NX')
            ->andReturn(true);

        $event = EventModel::factory()->create();

        Event::assertDispatched(
            NewEventBroadcast::class,
            fn (NewEventBroadcast $e) => $e->eventId === (string) $event->id
                && $e->userId === (string) $event->integration->user_id,
        );
    }

    #[Test]
    public function event_creation_throttles_when_lock_is_held(): void
    {
        Event::fake([NewEventBroadcast::class]);
        Redis::shouldReceive('set')->once()->andReturn(false);

        EventModel::factory()->create();

        Event::assertNotDispatched(NewEventBroadcast::class);
    }

    #[Test]
    public function database_notification_dispatches_broadcast(): void
    {
        Event::fake([NotificationReceived::class]);

        $user = User::factory()->create();
        $user->notify(new SystemMaintenance('scheduled', 'Brief downtime for upgrade'));

        Event::assertDispatched(
            NotificationReceived::class,
            fn (NotificationReceived $e) => $e->userId === (string) $user->id
                && $e->type === (new SystemMaintenance('scheduled', 'Brief downtime for upgrade'))->getNotificationType(),
        );
    }

    #[Test]
    public function notification_received_carries_db_notification_id(): void
    {
        Event::fake([NotificationReceived::class]);

        $user = User::factory()->create();
        $user->notify(new SystemMaintenance('scheduled', 'Brief downtime for upgrade'));

        $stored = DatabaseNotification::query()->where('notifiable_id', $user->id)->first();
        $this->assertNotNull($stored);

        Event::assertDispatched(
            NotificationReceived::class,
            fn (NotificationReceived $e) => $e->notificationId === (string) $stored->id,
        );
    }

    private function makeActionProgress(): ActionProgress
    {
        return ActionProgress::create([
            'user_id' => User::factory()->create()->id,
            'action_type' => 'sync',
            'action_id' => (string) Str::uuid(),
            'step' => 'processing',
            'message' => 'Working',
            'progress' => 25,
            'total' => 100,
        ]);
    }
}
