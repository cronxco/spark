<?php

namespace Tests\Feature\Console;

use App\Jobs\Fetch\FetchSingleUrl;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RepairFetchSubscriptionRevisionsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function dry_run_reports_without_dispatching_and_repair_is_idempotently_queued(): void
    {
        Queue::fake([FetchSingleUrl::class]);
        $integration = Integration::factory()->create(['service' => 'fetch']);
        $webpage = EventObject::factory()->create([
            'user_id' => $integration->user_id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'title' => 'Legacy subscription',
            'url' => 'https://example.com/briefing',
            'metadata' => [
                'fetch_mode' => 'recurring',
                'fetch_integration_id' => $integration->id,
                'content_hash' => 'legacy-hash',
            ],
        ]);

        $this->artisan('fetch:repair-subscription-revisions --dry-run')
            ->expectsOutputToContain('Dry run complete')
            ->assertSuccessful();
        Queue::assertNothingPushed();

        $this->artisan('fetch:repair-subscription-revisions')->assertSuccessful();
        $this->artisan('fetch:repair-subscription-revisions')->assertSuccessful();

        Queue::assertPushed(FetchSingleUrl::class, 1);
        $this->assertSame('legacy-hash', $webpage->fresh()->metadata['revision_repair_queued_for_hash']);

        $this->artisan('fetch:repair-subscription-revisions --force')->assertSuccessful();
        Queue::assertPushed(FetchSingleUrl::class, 1);
    }

    #[Test]
    public function a_cross_target_latest_event_pointer_does_not_mark_the_subscription_versioned(): void
    {
        Queue::fake([FetchSingleUrl::class]);
        $integration = Integration::factory()->create(['service' => 'fetch']);
        $webpage = EventObject::factory()->create([
            'user_id' => $integration->user_id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'url' => 'https://example.com/legacy',
            'metadata' => [
                'fetch_mode' => 'recurring',
                'fetch_integration_id' => $integration->id,
                'content_hash' => 'legacy-hash',
            ],
        ]);
        $otherWebpage = EventObject::factory()->create(['user_id' => $integration->user_id]);
        $otherEvent = Event::factory()->create([
            'integration_id' => $integration->id,
            'target_id' => $otherWebpage->id,
            'service' => 'fetch',
            'event_metadata' => ['revision_model_version' => 1],
        ]);
        $webpage->update([
            'metadata' => array_merge($webpage->metadata, ['latest_event_id' => $otherEvent->id]),
        ]);

        $this->artisan('fetch:repair-subscription-revisions')->assertSuccessful();

        Queue::assertPushed(FetchSingleUrl::class, 1);
    }
}
