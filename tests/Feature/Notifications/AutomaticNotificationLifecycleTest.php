<?php

namespace Tests\Feature\Notifications;

use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use App\Notifications\DailyDigestReady;
use App\Notifications\FetchMultipleFailures;
use App\Notifications\IntegrationFailed;
use App\Services\Fetch\FetchMetadata;
use App\Services\Notifications\NotificationFeedService;
use App\Services\Notifications\NotificationIncidentResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AutomaticNotificationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function successful_sync_resolves_the_incident_and_a_later_failure_opens_a_new_one(): void
    {
        $integration = Integration::factory()->create(['last_successful_update_at' => null]);
        $user = $integration->user;
        $user->notifyNow(new IntegrationFailed($integration, 'Failure'), ['database']);
        $first = $user->notifications()->first();

        $this->travel(1)->minutes();
        DB::transaction(fn () => $integration->markAsSuccessfullyUpdated());
        $this->assertSame('resolved', $first->fresh()->data['archive_reason']);
        $this->assertSame(0, app(NotificationFeedService::class)->feed($user)['counts']['unresolved_attention']);

        $this->travel(1)->minutes();
        $user->notifyNow(new IntegrationFailed($integration, 'New failure'), ['database']);
        $active = $user->notifications()->whereNull('archived_at')->get();
        $this->assertCount(1, $active);
        $this->assertNotSame($first->id, $active->first()->id);
        $this->assertSame(1, $active->first()->data['occurrence_count']);
    }

    #[Test]
    public function deleting_an_integration_archives_its_incident_after_commit(): void
    {
        $integration = Integration::factory()->create(['last_successful_update_at' => null]);
        $integration->user->notifyNow(new IntegrationFailed($integration, 'Failure'), ['database']);
        $alert = $integration->user->notifications()->first();

        DB::transaction(fn () => $integration->delete());

        $this->assertSame('entity_removed', $alert->fresh()->data['archive_reason']);
    }

    #[Test]
    public function rolled_back_recovery_does_not_resolve_an_incident(): void
    {
        $integration = Integration::factory()->create(['last_successful_update_at' => null]);
        $integration->user->notifyNow(new IntegrationFailed($integration, 'Failure'), ['database']);
        $alert = $integration->user->notifications()->first();
        $this->travel(1)->minutes();

        DB::beginTransaction();
        $integration->markAsSuccessfullyUpdated();
        DB::rollBack();

        $this->assertNull($alert->fresh()->archived_at);
    }

    #[Test]
    public function a_failure_delivered_after_recovery_is_archived_using_its_original_occurrence(): void
    {
        $integration = Integration::factory()->create(['last_successful_update_at' => null]);
        $user = $integration->user;
        $delayed = new IntegrationFailed($integration, 'Old failure');
        $delayed->via($user); // This runs before Laravel serialises queued delivery.
        $this->travel(1)->minutes();
        DB::transaction(fn () => $integration->markAsSuccessfullyUpdated());
        $this->travel(1)->minutes();

        $user->notifyNow($delayed, ['database']);

        $this->assertSame('resolved', $user->notifications()->first()->data['archive_reason']);
        $this->assertSame(0, $user->notifications()->whereNull('archived_at')->count());
    }

    #[Test]
    public function an_older_queued_failure_does_not_replace_the_latest_failure(): void
    {
        $integration = Integration::factory()->create(['last_successful_update_at' => null]);
        $user = $integration->user;
        $older = new IntegrationFailed($integration, 'Older failure');
        $older->via($user);
        $this->travel(1)->minutes();
        $user->notifyNow(new IntegrationFailed($integration, 'Latest failure'), ['database']);
        $before = $user->notifications()->first()->data['last_occurred_at'];
        $this->travel(1)->minutes();
        $user->notifyNow($older, ['database']);

        $active = $user->notifications()->whereNull('archived_at')->first();
        $this->assertSame(2, $active->data['occurrence_count']);
        $this->assertSame('Latest failure', $active->data['technical_detail']);
        $this->assertSame($before, $active->data['last_occurred_at']);
    }

    #[Test]
    public function a_stale_resolution_cannot_clear_a_later_repeat_failure(): void
    {
        $integration = Integration::factory()->create(['last_successful_update_at' => null]);
        $user = $integration->user;
        $user->notifyNow(new IntegrationFailed($integration, 'Failure'), ['database']);
        $cutoff = now();
        $this->travel(1)->minutes();
        $user->notifyNow(new IntegrationFailed($integration, 'Later failure'), ['database']);

        $count = app(NotificationIncidentResolver::class)->resolve($user, ["integration_failed:{$integration->id}"], $cutoff);

        $this->assertSame(0, $count);
        $this->assertSame(1, $user->notifications()->whereNull('archived_at')->count());
    }

    #[Test]
    public function a_successful_page_fetch_and_page_deletion_resolve_their_incidents(): void
    {
        $object = EventObject::factory()->create(['metadata' => ['last_error' => ['message' => 'blocked']]]);
        $user = $object->user;
        $user->notifyNow(new FetchMultipleFailures($object, 3, 'blocked'), ['database']);
        $first = $user->notifications()->first();
        $this->travel(1)->minutes();

        FetchMetadata::merge($object, ['last_checked_at' => now()->toJSON(), 'last_error' => null]);

        $this->assertSame('resolved', $first->fresh()->data['archive_reason']);
        $this->travel(1)->minutes();
        FetchMetadata::merge($object, ['last_error' => ['message' => 'blocked again']]);
        $user->notifyNow(new FetchMultipleFailures($object, 3, 'blocked again'), ['database']);
        DB::transaction(fn () => $object->delete());
        $this->assertSame(0, $user->notifications()->whereNull('archived_at')->count());
        $this->assertSame(1, $user->notifications()->get()->filter(fn ($n) => ($n->data['archive_reason'] ?? null) === 'entity_removed')->count());
    }

    #[Test]
    public function maintenance_repairs_old_alerts_and_expires_digests_despite_metadata_timestamp_changes(): void
    {
        $integration = Integration::factory()->create(['last_successful_update_at' => now()]);
        $user = $integration->user;
        $alert = $this->legacy($user, 'integration_failed', "integration_failed:{$integration->id}");
        $digest = $this->legacy($user, 'daily_digest');
        $fetch = $this->legacy($user, 'fetch_multiple_failures');

        $this->artisan('notifications:maintain-history', ['--dry-run' => true])->assertSuccessful();
        $this->assertNull($alert->fresh()->archived_at);
        $this->assertNull($digest->fresh()->archived_at);
        $this->artisan('notifications:maintain-history')->assertSuccessful();

        $this->assertSame('resolved', $alert->fresh()->data['archive_reason']);
        $this->assertSame('expired', $digest->fresh()->data['archive_reason']);
        $this->assertSame('legacy_unverified', $fetch->fresh()->data['archive_reason']);
    }

    #[Test]
    public function new_digests_supersede_only_the_same_period(): void
    {
        $user = User::factory()->create();
        $object = EventObject::factory()->create(['user_id' => $user->id]);
        $user->notifyNow(new DailyDigestReady($object, 'morning'), ['database']);
        $old = $user->notifications()->first();
        $this->travel(1)->minutes();
        $evening = EventObject::factory()->create(['user_id' => $user->id]);
        $user->notifyNow(new DailyDigestReady($evening, 'evening'), ['database']);
        $this->travel(1)->minutes();
        $new = EventObject::factory()->create(['user_id' => $user->id]);
        $user->notifyNow(new DailyDigestReady($new, 'morning'), ['database']);

        $this->assertSame('superseded', $old->fresh()->data['archive_reason']);
        $this->assertSame(2, $user->notifications()->whereNull('archived_at')->count());
    }

    private function legacy(User $user, string $type, ?string $key = null): DatabaseNotification
    {
        return $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => $type,
            'data' => ['type' => $type, 'title' => 'Historical item'],
            'group_key' => $key,
            'created_at' => now()->subDays(10),
            'updated_at' => now(),
        ]);
    }
}
