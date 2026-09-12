<?php

namespace Tests\Feature\Console;

use App\Jobs\Fetch\FetchSingleUrl;
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
    }
}
