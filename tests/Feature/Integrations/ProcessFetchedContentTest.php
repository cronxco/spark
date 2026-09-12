<?php

namespace Tests\Feature\Integrations;

use App\Jobs\Data\Fetch\ProcessFetchedContent;
use App\Jobs\TaskPipeline\ProcessTaskPipelineJob;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessFetchedContentTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function changed_content_creates_an_immutable_revision_and_latest_pointer(): void
    {
        config(['app.enable_task_pipeline' => true]);
        Queue::fake([ProcessTaskPipelineJob::class]);
        Carbon::setTestNow('2026-09-12 09:00:00 UTC');
        [$integration, $webpage] = $this->subscription();

        $this->process($integration, $webpage, 'hash-one', 'Morning headline', 'morning-run');

        $event = Event::sole();
        $this->assertSame('fetched', $event->action);
        $this->assertSame('Morning headline', $event->target_metadata['title']);
        $this->assertSame('2026-09-12', $event->event_metadata['fetch_day']);
        $this->assertSame('Raw Morning headline', $event->blocks()->where('block_type', 'fetch_content')->sole()->metadata['text']);
        $this->assertSame($event->id, $webpage->refresh()->metadata['latest_event_id']);
        Queue::assertPushed(ProcessTaskPipelineJob::class, fn (ProcessTaskPipelineJob $job) => $job->model instanceof Event);
    }

    #[Test]
    public function a_second_change_that_day_supersedes_but_does_not_mutate_the_first_revision(): void
    {
        Queue::fake([ProcessTaskPipelineJob::class]);
        Carbon::setTestNow('2026-09-12 09:00:00 UTC');
        [$integration, $webpage] = $this->subscription();
        $this->process($integration, $webpage, 'hash-one', 'Morning headline', 'morning-run');
        $first = Event::sole();

        Carbon::setTestNow('2026-09-12 18:00:00 UTC');
        $this->process($integration, $webpage->fresh(), 'hash-two', 'Evening headline', 'evening-run');

        $this->assertSame('updated', $first->fresh()->action);
        $latest = Event::where('action', 'fetched')->sole();
        $this->assertSame('Evening headline', $latest->target_metadata['title']);
        $this->assertSame('Morning headline', $first->fresh()->target_metadata['title']);
        $this->assertSame('Raw Morning headline', $first->blocks()->where('block_type', 'fetch_content')->sole()->metadata['text']);
        $this->assertSame($latest->id, $webpage->fresh()->metadata['latest_event_id']);
    }

    #[Test]
    public function unchanged_content_updates_fetch_statistics_without_creating_a_revision(): void
    {
        Queue::fake([ProcessTaskPipelineJob::class]);
        [$integration, $webpage] = $this->subscription(['content_hash' => 'same-hash', 'fetch_count' => 4]);

        $this->process($integration, $webpage, 'same-hash', 'Same headline', 'unchanged-run');

        $this->assertDatabaseCount('events', 0);
        $this->assertSame(5, $webpage->fresh()->metadata['fetch_count']);
        Queue::assertNotPushed(
            ProcessTaskPipelineJob::class,
            fn (ProcessTaskPipelineJob $job) => $job->model instanceof Event,
        );
    }

    #[Test]
    public function the_final_revision_from_a_previous_day_remains_visible(): void
    {
        Queue::fake([ProcessTaskPipelineJob::class]);
        [$integration, $webpage] = $this->subscription();
        Carbon::setTestNow('2026-09-12 21:00:00 UTC');
        $this->process($integration, $webpage, 'hash-one', 'Saturday', 'saturday-run');

        Carbon::setTestNow('2026-09-13 01:00:00 UTC');
        $this->process($integration, $webpage->fresh(), 'hash-two', 'Sunday', 'sunday-run');

        $this->assertSame(2, Event::where('action', 'fetched')->count());
    }

    #[Test]
    public function retrying_the_same_fetch_run_does_not_create_a_duplicate_revision(): void
    {
        Queue::fake([ProcessTaskPipelineJob::class]);
        [$integration, $webpage] = $this->subscription();

        $this->process($integration, $webpage, 'hash-one', 'Headline', 'stable-run-id');
        $this->process($integration, $webpage->fresh(), 'hash-one', 'Headline', 'stable-run-id');

        $this->assertDatabaseCount('events', 1);
        $this->assertDatabaseCount('blocks', 1);
    }

    #[Test]
    public function spring_forward_uses_the_next_local_midnight_as_the_day_boundary(): void
    {
        Queue::fake([ProcessTaskPipelineJob::class]);
        [$integration, $webpage] = $this->subscription([], ['schedule_timezone' => 'America/New_York']);
        $nextLocalDay = Event::factory()->create([
            'integration_id' => $integration->id,
            'target_id' => $webpage->id,
            'service' => 'fetch',
            'domain' => 'knowledge',
            'action' => 'fetched',
            'time' => Carbon::parse('2026-03-09 04:30:00 UTC'),
        ]);
        Carbon::setTestNow('2026-03-08 16:00:00 UTC');

        $this->process($integration, $webpage, 'spring-hash', 'Spring update', 'spring-run');

        $this->assertSame('fetched', $nextLocalDay->fresh()->action);
    }

    #[Test]
    public function autumn_fallback_includes_the_final_hour_of_the_local_day(): void
    {
        Queue::fake([ProcessTaskPipelineJob::class]);
        [$integration, $webpage] = $this->subscription([], ['schedule_timezone' => 'America/New_York']);
        $lateCurrentDay = Event::factory()->create([
            'integration_id' => $integration->id,
            'target_id' => $webpage->id,
            'service' => 'fetch',
            'domain' => 'knowledge',
            'action' => 'fetched',
            'time' => Carbon::parse('2026-11-02 04:30:00 UTC'),
        ]);
        Carbon::setTestNow('2026-11-01 17:00:00 UTC');

        $this->process($integration, $webpage, 'autumn-hash', 'Autumn update', 'autumn-run');

        $this->assertSame('updated', $lateCurrentDay->fresh()->action);
    }

    private function subscription(array $metadata = [], array $configuration = []): array
    {
        $integration = Integration::factory()->create([
            'service' => 'fetch',
            'configuration' => array_merge(['schedule_timezone' => 'UTC'], $configuration),
        ]);
        $webpage = EventObject::factory()->create([
            'user_id' => $integration->user_id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'title' => 'Subscription',
            'url' => 'https://example.com/briefing',
            'metadata' => array_merge([
                'fetch_mode' => 'recurring',
                'fetch_integration_id' => $integration->id,
                'fetch_count' => 0,
                'enabled' => true,
            ], $metadata),
        ]);

        return [$integration, $webpage];
    }

    private function process(Integration $integration, EventObject $webpage, string $hash, string $title, string $runId): void
    {
        (new ProcessFetchedContent(
            integration: $integration,
            webpage: $webpage,
            extracted: [
                'title' => $title,
                'content' => '<article>' . $title . '</article>',
                'text_content' => 'Raw ' . $title,
                'excerpt' => 'Excerpt ' . $title,
                'image' => 'https://example.com/' . $runId . '.jpg',
            ],
            contentHash: $hash,
            fetchRunId: $runId,
        ))->handle();
    }
}
