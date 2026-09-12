<?php

namespace Tests\Feature\Notifications;

use App\Models\ActionProgress;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use App\Notifications\DailyDigestReady;
use App\Notifications\IntegrationFailed;
use App\Services\Notifications\NotificationFeedService;
use App\Services\Notifications\NotificationIncidentResolver;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NotificationFeedLifecycleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function repeated_incidents_coalesce_and_keep_technical_copy_out_of_the_human_message(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $technicalError = 'GET https://example.test/feed?token=secret returned 500';

        $notification = new IntegrationFailed($integration, $technicalError);
        $this->assertStringNotContainsString('token=secret', $notification->getMessage());
        $this->assertStringContainsString('REDACTED', $notification->getTechnicalDetail());

        $user->notifyNow($notification);
        $user->notifyNow(new IntegrationFailed($integration, $technicalError));

        $active = $user->notifications()->whereNull('archived_at')->get();
        $this->assertCount(1, $active);
        $this->assertSame(2, $active->first()->data['occurrence_count']);
        $this->assertSame("integration_failed:{$integration->id}", $active->first()->group_key);
    }

    #[Test]
    public function technical_detail_redacts_colon_and_json_style_credentials(): void
    {
        $integration = Integration::factory()->create();
        $notification = new IntegrationFailed(
            $integration,
            'POST https://example.test failed: {"api_key": "super-secret"} with X-Api-Key: another-secret',
        );

        $detail = $notification->getTechnicalDetail();

        $this->assertStringNotContainsString('super-secret', $detail);
        $this->assertStringNotContainsString('another-secret', $detail);
        $this->assertStringContainsString('[REDACTED]', $detail);
    }

    #[Test]
    public function feed_service_detail_redacts_colon_and_json_style_credentials(): void
    {
        $user = User::factory()->create();
        $notification = $this->notification($user, 'integration_failed', now(), [
            'technical_detail' => '{"api_key": "super-secret"} X-Api-Key: another-secret',
        ]);

        $detail = app(NotificationFeedService::class)->detail($user, $notification->id);

        $this->assertStringNotContainsString('super-secret', $detail['technical_detail']);
        $this->assertStringNotContainsString('another-secret', $detail['technical_detail']);
        $this->assertStringContainsString('[REDACTED]', $detail['technical_detail']);
    }

    #[Test]
    public function daily_digest_group_key_is_scoped_to_the_digest_object_not_the_recurring_period(): void
    {
        $user = User::factory()->create();
        $today = EventObject::factory()->create(['user_id' => $user->id, 'concept' => 'digest', 'type' => 'morning_digest']);
        $tomorrow = EventObject::factory()->create(['user_id' => $user->id, 'concept' => 'digest', 'type' => 'morning_digest']);

        $todayDigest = new DailyDigestReady($today, 'morning');
        $tomorrowDigest = new DailyDigestReady($tomorrow, 'morning');

        $this->assertSame("daily_digest:{$today->id}", $todayDigest->getGroupKey());
        $this->assertNotSame($todayDigest->getGroupKey(), $tomorrowDigest->getGroupKey());
    }

    #[Test]
    public function a_recovered_incident_moves_to_history_without_being_deleted(): void
    {
        $user = User::factory()->create();
        $notification = $this->notification($user, 'integration_failed', now(), [
            'group_key' => 'integration_failed:example',
            'title' => 'Sync stopped',
        ], 'integration_failed:example');

        $resolved = app(NotificationIncidentResolver::class)->resolve($user, ['integration_failed:example']);

        $this->assertSame(1, $resolved);
        $this->assertNotNull($notification->fresh()->archived_at);
        $this->assertSame('resolved', $notification->fresh()->data['archive_reason']);
    }

    #[Test]
    public function maintenance_expires_ephemeral_items_and_only_prunes_terminal_old_progress(): void
    {
        $user = User::factory()->create();
        $expired = $this->notification($user, 'daily_digest', now()->subHours(25), ['title' => 'Old digest']);
        $persistent = $this->notification($user, 'integration_failed', now()->subDays(10), ['title' => 'Still broken']);
        $oldHistory = $this->notification($user, 'daily_digest', now()->subDays(40), ['title' => 'Old history']);
        $oldHistory->forceFill(['archived_at' => now()->subDays(31)])->save();

        $activeProgress = ActionProgress::createProgress(
            (string) $user->id,
            'migration',
            (string) Str::uuid(),
            'processing',
            'Still working',
        );
        $activeProgress->forceFill(['created_at' => now()->subDays(40), 'updated_at' => now()->subDays(40)])->saveQuietly();

        $terminalProgress = ActionProgress::createProgress(
            (string) $user->id,
            'export',
            (string) Str::uuid(),
            'complete',
            'Finished',
            100,
        );
        $terminalProgress->forceFill([
            'completed_at' => now()->subDays(40),
            'created_at' => now()->subDays(40),
            'updated_at' => now()->subDays(40),
        ])->saveQuietly();

        $this->artisan('notifications:maintain-history')->assertSuccessful();

        $this->assertNotNull($expired->fresh()->archived_at);
        $this->assertNull($persistent->fresh()->archived_at);
        $this->assertNull($oldHistory->fresh());
        $this->assertNotNull($activeProgress->fresh());
        $this->assertNull($terminalProgress->fresh());
    }

    /** @param array<string, mixed> $data */
    private function notification(
        User $user,
        string $type,
        DateTimeInterface $createdAt,
        array $data,
        ?string $groupKey = null,
    ): DatabaseNotification {
        return DatabaseNotification::query()->create([
            'id' => (string) Str::uuid(),
            'type' => $type,
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'data' => ['type' => $type, ...$data],
            'group_key' => $groupKey,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
