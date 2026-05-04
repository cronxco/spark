<?php

namespace Tests\Feature\Knowledge;

use App\Jobs\Knowledge\ProcessMissingKnowledgeSummariesJob;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReprocessMissingKnowledgeSummariesCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function dry_run_reports_missing_knowledge_events_without_dispatching(): void
    {
        Queue::fake();
        $this->createFetchEvent();

        $this->artisan('knowledge:reprocess-missing-summaries --dry-run')
            ->expectsOutput('Found 1 knowledge event(s) missing TLDR blocks.')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function command_dispatches_scanner_job(): void
    {
        Queue::fake();

        $this->artisan('knowledge:reprocess-missing-summaries --service=fetch --limit=25')
            ->expectsOutput('Missing knowledge summary reprocessing job dispatched.')
            ->assertExitCode(0);

        Queue::assertPushed(ProcessMissingKnowledgeSummariesJob::class, fn (ProcessMissingKnowledgeSummariesJob $job) => $job->service === 'fetch'
            && $job->limit === 25);
    }

    private function createFetchEvent(): Event
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'fetch',
        ]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'fetch',
        ]);
        $actor = EventObject::factory()->create(['user_id' => $user->id]);
        $target = EventObject::factory()->create([
            'user_id' => $user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'title' => 'Example Article',
            'content' => 'Extracted content.',
        ]);

        return Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'fetch',
            'domain' => 'knowledge',
            'action' => 'fetched',
            'actor_id' => $actor->id,
            'target_id' => $target->id,
        ]);
    }
}
