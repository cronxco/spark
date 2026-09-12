<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BackfillNotificationMetadataTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function rerunning_the_backfill_does_not_reset_an_already_normalised_notifications_updated_at(): void
    {
        $user = User::factory()->create();

        $notification = DatabaseNotification::query()->create([
            'id' => (string) Str::uuid(),
            'type' => 'integration_failed',
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'data' => ['title' => 'Reconnect Monzo', 'entity_id' => (string) Str::uuid()],
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);
        // Reload rather than reuse the in-memory Carbon: the column has
        // whole-second precision, so the persisted value is truncated versus
        // what we just passed in.
        $staleUpdatedAt = $notification->fresh()->updated_at;

        $this->artisan('notifications:backfill-metadata')->assertSuccessful();

        // The backfill is a one-time data/schema normalisation, not a
        // lifecycle event: it must never touch updated_at, not even on the
        // run that first adds the new metadata, or it resets the notification's
        // expiry clock in MaintainNotificationHistory.
        $afterFirstRun = $notification->fresh()->updated_at;
        $this->assertTrue(
            $afterFirstRun->equalTo($staleUpdatedAt),
            'The normalising run must not touch updated_at, even though it changes other fields.',
        );
        $this->assertSame('integration_failed', $notification->fresh()->data['type']);
        $this->assertNotNull($notification->fresh()->group_key);

        $this->artisan('notifications:backfill-metadata')->assertSuccessful();

        $this->assertTrue(
            $notification->fresh()->updated_at->equalTo($afterFirstRun),
            'Re-running the backfill against an already-normalised notification must not '
                . 'bump updated_at, or MaintainNotificationHistory keeps postponing its expiry.',
        );
    }
}
