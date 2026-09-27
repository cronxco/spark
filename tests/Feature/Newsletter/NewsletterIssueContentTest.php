<?php

namespace Tests\Feature\Newsletter;

use App\Http\Resources\EventResource;
use App\Jobs\TaskPipeline\ProcessTaskPipelineJob;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use App\Services\DaySummaryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NewsletterIssueContentTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Integration $integration;

    protected EventObject $publication;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $this->user->id, 'service' => 'newsletter']);
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => 'newsletter',
        ]);
        $this->publication = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'publication',
            'type' => 'newsletter_publication',
            'title' => 'The Economist Today',
            'content' => 'The latest issue',
        ]);
    }

    #[Test]
    public function an_event_reads_back_its_own_issue_rather_than_the_latest(): void
    {
        $event = $this->issue('Back and shoulder surgery is often worse than useless', 'This issue only');

        $resource = (new EventResource($event->load(['target', 'blocks'])))->resolve(request());

        $this->assertSame('This issue only', $resource['target']['content']);
        $this->assertSame([], collect($resource['blocks'] ?? [])->where('block_type', 'newsletter_content')->all());
    }

    #[Test]
    public function the_day_summary_titles_a_newsletter_by_its_subject_and_names_the_publication(): void
    {
        $this->issue('Back and shoulder surgery is often worse than useless', 'This issue only');

        $summary = app(DaySummaryService::class)->generateSummary($this->user, Carbon::today(), ['knowledge']);
        $newsletter = $summary['sections']['knowledge']['newsletters'][0];

        $this->assertSame('Back and shoulder surgery is often worse than useless', $newsletter['title']);
        $this->assertSame('The Economist Today', $newsletter['from']);
    }

    #[Test]
    public function the_backfill_queues_only_recent_issues_without_their_own_text(): void
    {
        Queue::fake([ProcessTaskPipelineJob::class]);
        $this->issue('Already done', 'Has its own text');
        $missing = $this->issue('Needs it', null);
        $this->issue('Too old', null, Carbon::now()->subDays(30));

        $this->artisan('newsletter:backfill-issue-content', ['--days' => 14, '--dry-run' => true])
            ->expectsOutputToContain('Found 1 newsletter issue(s)')
            ->assertSuccessful();
        Queue::assertNothingPushed();

        $this->artisan('newsletter:backfill-issue-content', ['--days' => 14])->assertSuccessful();

        Queue::assertPushed(ProcessTaskPipelineJob::class, 1);
        Queue::assertPushed(ProcessTaskPipelineJob::class, fn ($job) => $job->model->is($missing));
    }

    private function issue(string $subject, ?string $content, ?Carbon $time = null): Event
    {
        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'newsletter',
            'domain' => 'knowledge',
            'action' => 'received_post',
            'target_id' => $this->publication->id,
            'time' => $time ?? Carbon::today()->setHour(9),
            'event_metadata' => ['email_subject' => $subject, 'raw_html' => '<html>' . $subject . '</html>'],
        ]);

        if ($content !== null) {
            $event->createBlock([
                'title' => 'Issue Content',
                'block_type' => 'newsletter_content',
                'time' => $event->time,
                'metadata' => ['content' => $content],
            ]);
        }

        return $event;
    }
}
