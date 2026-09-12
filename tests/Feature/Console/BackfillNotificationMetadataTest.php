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
        $staleUpdatedAt = now()->subDays(10);

        $notification = DatabaseNotification::query()->create([
            'id' => (string) Str::uuid(),
            'type' => 'integration_failed',
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'data' => ['title' => 'Reconnect Monzo', 'entity_id' => (string) Str::uuid()],
            'created_at' => $staleUpdatedAt,
            'updated_at' => $staleUpdatedAt,
        ]);

        $this->artisan('notifications:backfill-metadata')->assertSuccessful();

        $afterFirstRun = $notification->fresh()->updated_at;
        $this->assertTrue(
            $afterFirstRun->gt($staleUpdatedAt),
            'The first normalising run adds real metadata and is a genuine update.',
        );
        $this->assertSame('integration_failed', $notification->fresh()->data['type']);

        $this->artisan('notifications:backfill-metadata')->assertSuccessful();

        $this->assertTrue(
            $notification->fresh()->updated_at->equalTo($afterFirstRun),
            'Re-running the backfill against an already-normalised notification must not '
                . 'bump updated_at, or MaintainNotificationHistory keeps postponing its expiry.',
        );
    }
}
