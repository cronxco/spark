<?php

namespace Tests\Unit\Services\Notifications;

use App\Models\User;
use App\Services\Notifications\NotificationArchiver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NotificationArchiverTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_archives_a_notification_and_merges_the_reason_into_its_data(): void
    {
        $user = User::factory()->create();
        $notification = DatabaseNotification::query()->create([
            'id' => (string) Str::uuid(),
            'type' => 'integration_failed',
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'data' => ['title' => 'Sync stopped'],
        ]);

        $archived = app(NotificationArchiver::class)->archive($notification, 'manual');

        $this->assertNotNull($archived);
        $this->assertNotNull($archived->archived_at);
        $this->assertSame('manual', $archived->data['archive_reason']);
        $this->assertSame('Sync stopped', $archived->data['title']);
    }

    #[Test]
    public function it_does_not_move_an_already_archived_timestamp_on_a_second_call(): void
    {
        $user = User::factory()->create();
        $notification = DatabaseNotification::query()->create([
            'id' => (string) Str::uuid(),
            'type' => 'integration_failed',
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'data' => ['title' => 'Sync stopped'],
        ]);

        $archiver = app(NotificationArchiver::class);
        $archiver->archive($notification, 'manual');
        // Reload from the database (rather than comparing the in-memory value
        // from the first call) so both sides go through the same
        // whole-second `timestamp` column precision.
        $firstArchivedAt = $notification->fresh()->archived_at;

        $archiver->archive($notification->fresh(), 'resolved');
        $secondArchivedAt = $notification->fresh()->archived_at;

        $this->assertSame($firstArchivedAt, $secondArchivedAt);
    }

    #[Test]
    public function it_returns_null_for_a_notification_that_no_longer_exists(): void
    {
        $user = User::factory()->create();
        $notification = DatabaseNotification::query()->create([
            'id' => (string) Str::uuid(),
            'type' => 'integration_failed',
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'data' => [],
        ]);
        $notification->delete();

        $this->assertNull(app(NotificationArchiver::class)->archive($notification, 'manual'));
    }
}
