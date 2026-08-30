<?php

namespace Tests\Feature\Flint;

use App\Jobs\Flint\SendDigestNotificationJob;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\TaskExecution;
use App\Models\User;
use App\Notifications\DailyDigestReady;
use App\Services\FlintDigestService;
use App\Services\TaskPipeline\TaskExecutionStore;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Integration $flintIntegration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'test@example.com',
        ]);

        $this->flintIntegration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'flint',
            'instance_type' => 'digest',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function sends_digest_notification_when_digest_exists(): void
    {
        Notification::fake();

        $digest = $this->createDigest('morning');

        $this->runJob($this->user, '06:00');

        Notification::assertSentTo(
            [$this->user],
            DailyDigestReady::class,
            fn (DailyDigestReady $notification) => $notification->digestObject->id === $digest->target_id
                && $notification->period === 'morning'
                && $notification->title === 'Your Morning Digest',
        );
    }

    #[Test]
    public function does_not_send_notification_when_no_digest_found(): void
    {
        Notification::fake();

        $this->runJob($this->user, '06:00');

        Notification::assertNothingSent();

        // Silently doing nothing is what hid this job for weeks — it now leaves
        // a row saying why.
        $execution = TaskExecution::where('task_key', 'flint_digest_notification')->firstOrFail();
        $this->assertSame('not_applicable', $execution->status);
    }

    #[Test]
    public function it_records_a_task_execution_when_it_announces_a_digest(): void
    {
        Notification::fake();

        $this->createDigest('morning');

        $this->runJob($this->user, '06:00');

        $integration = app(FlintDigestService::class)->resolveIntegration($this->user);

        $execution = TaskExecution::where('entity_type', 'integration')
            ->where('entity_id', $integration->id)
            ->where('task_key', 'flint_digest_notification')
            ->firstOrFail();

        $this->assertSame('success', $execution->status);
        $this->assertSame($this->user->id, $execution->user_id);
    }

    #[Test]
    public function notification_carries_the_digest_summary(): void
    {
        Notification::fake();

        $this->createDigest('evening', [
            'title' => 'Evening Digest',
            'summary' => 'Your evening summary.',
        ]);

        $this->runJob($this->user, '18:00');

        Notification::assertSentTo(
            [$this->user],
            DailyDigestReady::class,
            fn (DailyDigestReady $notification) => $notification->period === 'evening'
                && $notification->title === 'Evening Digest'
                && $notification->summary === 'Your evening summary.',
        );
    }

    #[Test]
    public function it_counts_unanswered_questions_for_the_notification(): void
    {
        Notification::fake();

        $digest = $this->createDigest('evening');

        $digest->createBlock([
            'block_type' => 'flint_user_question',
            'title' => 'Answered already',
            'time' => $digest->time,
            'metadata' => ['question' => 'How was the run?', 'answer' => 'Good'],
        ]);
        $digest->createBlock([
            'block_type' => 'flint_user_question',
            'title' => 'Still open',
            'time' => $digest->time,
            'metadata' => ['question' => 'Why the late night?', 'answer' => null],
        ]);

        $this->runJob($this->user, '18:00');

        Notification::assertSentTo(
            [$this->user],
            DailyDigestReady::class,
            fn (DailyDigestReady $notification) => $notification->unansweredQuestionCount === 1,
        );
    }

    #[Test]
    public function it_announces_the_digest_it_was_handed_by_id(): void
    {
        Notification::fake();

        $this->createDigest('morning', ['title' => 'Morning Digest']);
        $evening = $this->createDigest('evening', ['title' => 'Evening Digest']);

        // The schedule time says morning, but an explicit event id wins.
        $this->runJob($this->user, '06:00', 'evening', $evening->id);

        Notification::assertSentTo(
            [$this->user],
            DailyDigestReady::class,
            fn (DailyDigestReady $notification) => $notification->title === 'Evening Digest',
        );
    }

    #[Test]
    public function it_does_not_notify_about_another_users_digest(): void
    {
        Notification::fake();

        $other = User::factory()->create();
        $otherIntegration = Integration::factory()->create([
            'user_id' => $other->id,
            'service' => 'flint',
            'instance_type' => 'digest',
        ]);

        Event::factory()->create([
            'integration_id' => $otherIntegration->id,
            'service' => 'flint',
            'action' => 'had_summary',
            'time' => Carbon::parse(now()->toDateString(), 'UTC'),
            'event_metadata' => ['period' => 'morning', 'title' => 'Not yours'],
        ]);

        $this->runJob($this->user, '06:00');

        Notification::assertNothingSent();
    }

    /**
     * The digest lookup must use the user's effective-timezone local day, not
     * the server (UTC) day. A far-west user's digest is dated for their local
     * day but stored at that date's 00:00 UTC marker (see FlintDigestService);
     * once UTC has rolled past midnight, a server-tz `startOfDay()` bound would
     * wrongly exclude it. Reproduces and guards the far-west day-boundary bug.
     */
    #[Test]
    public function finds_digest_using_effective_timezone_local_day_for_far_west_user(): void
    {
        Notification::fake();

        $this->user->setTimezone('Pacific/Honolulu'); // UTC-10, no time_travel event => profile tz

        $localDate = '2026-06-14';

        // The user is late in their local day; UTC has already rolled to the 15th.
        Carbon::setTestNow(Carbon::parse("{$localDate} 22:00", 'Pacific/Honolulu')); // = 2026-06-15 08:00 UTC

        // 2026-06-14 00:00 UTC — BEFORE now()->startOfDay() (= 2026-06-15 00:00 UTC).
        $digest = $this->createDigest('evening', [], Carbon::parse($localDate, 'UTC'));

        $this->runJob($this->user, '18:00');

        Notification::assertSentTo(
            [$this->user],
            DailyDigestReady::class,
            fn (DailyDigestReady $notification) => $notification->digestObject->id === $digest->target_id
                && $notification->period === 'evening',
        );
    }

    private function runJob(User $user, string $scheduleTime, ?string $period = null, ?string $eventId = null): void
    {
        (new SendDigestNotificationJob($user, $scheduleTime, $period, $eventId))
            ->handle(app(FlintDigestService::class), app(TaskExecutionStore::class));
    }

    /**
     * Create a digest the way the Flint routine does through
     * create-flint-digest: a `had_summary` event carrying the title and summary
     * prose in `event_metadata`, targeting the digest object.
     */
    private function createDigest(string $period, array $metadata = [], ?Carbon $time = null): Event
    {
        $time ??= Carbon::parse(now()->toDateString(), 'UTC');

        $digestObject = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'digest',
            'type' => $period . '_digest',
            'title' => $time->format('Y-m-d') . ' ' . strtoupper(substr($period, 0, 3)),
            'time' => $time,
        ]);

        return Event::factory()->create([
            'integration_id' => $this->flintIntegration->id,
            'target_id' => $digestObject->id,
            'service' => 'flint',
            'action' => 'had_summary',
            'time' => $time,
            'event_metadata' => array_merge([
                'period' => $period,
                'digest_object_id' => $digestObject->id,
                'title' => 'Your ' . ucfirst($period) . ' Digest',
                'summary' => 'You slept well and ran 5k. Tomorrow looks quieter.',
            ], $metadata),
        ]);
    }
}
