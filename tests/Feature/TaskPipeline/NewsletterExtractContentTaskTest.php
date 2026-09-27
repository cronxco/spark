<?php

namespace Tests\Feature\TaskPipeline;

use App\Integrations\Newsletter\NewsletterPlugin;
use App\Jobs\TaskPipeline\ProcessTaskPipelineJob;
use App\Jobs\TaskPipeline\Tasks\NewsletterExtractContentTask;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Services\TaskPipeline\TaskDefinition;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NewsletterExtractContentTaskTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_extracts_newsletter_content_and_marks_success(): void
    {
        Queue::fake([ProcessTaskPipelineJob::class]);
        $fake = OpenAI::fake([$this->openAiResponse('# Clean Newsletter')]);

        [$event, $publication] = $this->newsletterEvent(rawHtml: '<html>Raw newsletter</html>');

        (new NewsletterExtractContentTask($event, $this->task()))->handle();

        $this->assertSame('# Clean Newsletter', $publication->refresh()->content);
        $this->assertNotEmpty($publication->metadata['extracted_at']);
        $this->assertSame('success', $event->refresh()->event_metadata['task_executions']['newsletter_extract_content']['last_attempt']['status']);
        Queue::assertPushed(ProcessTaskPipelineJob::class);
        $fake->assertSent(Chat::class, 1);
    }

    #[Test]
    public function it_keeps_each_issue_text_on_its_own_event(): void
    {
        Queue::fake([ProcessTaskPipelineJob::class]);
        OpenAI::fake([$this->openAiResponse('# Monday issue'), $this->openAiResponse('# Tuesday issue')]);

        [$monday, $publication] = $this->newsletterEvent(rawHtml: '<html>Monday</html>');
        $monday->update(['time' => now()->subDay()]);
        $tuesday = Event::factory()->create([
            'time' => now(),
            'integration_id' => $monday->integration_id,
            'target_id' => $publication->id,
            'service' => 'newsletter',
            'domain' => 'knowledge',
            'action' => 'received_post',
            'event_metadata' => ['email_subject' => 'Tuesday', 'raw_html' => '<html>Tuesday</html>'],
        ]);

        (new NewsletterExtractContentTask($monday, $this->task()))->handle();
        (new NewsletterExtractContentTask($tuesday, $this->task()))->handle();

        $this->assertSame('# Monday issue', $monday->fresh()->issueContent());
        $this->assertSame('# Monday issue', $monday->fresh()->displayTargetContent());
        $this->assertSame('# Tuesday issue', $tuesday->fresh()->issueContent());
        $this->assertSame('# Tuesday issue', $publication->refresh()->content);
    }

    #[Test]
    public function re_extracting_an_older_issue_leaves_the_publications_latest_text_alone(): void
    {
        Queue::fake([ProcessTaskPipelineJob::class]);
        OpenAI::fake([$this->openAiResponse('# Monday issue')]);

        [$monday, $publication] = $this->newsletterEvent(rawHtml: '<html>Monday</html>');
        $monday->update(['time' => now()->subDay()]);
        $publication->update(['content' => '# Tuesday issue']);
        Event::factory()->create([
            'integration_id' => $monday->integration_id,
            'target_id' => $publication->id,
            'service' => 'newsletter',
            'domain' => 'knowledge',
            'action' => 'received_post',
            'time' => now(),
        ]);

        (new NewsletterExtractContentTask($monday, $this->task()))->handle();

        $this->assertSame('# Monday issue', $monday->fresh()->issueContent());
        $this->assertSame('# Tuesday issue', $publication->refresh()->content);
    }

    #[Test]
    public function a_soft_deleted_issue_block_is_neither_read_nor_counted_as_extracted(): void
    {
        [$event, $publication] = $this->newsletterEvent(rawHtml: '<html>Issue</html>');
        $publication->update(['content' => '# Publication text']);
        $event->createBlock([
            'title' => 'Issue Content',
            'block_type' => 'newsletter_content',
            'time' => $event->time,
            'metadata' => ['content' => '# Deleted text'],
        ])->delete();

        $definition = collect(NewsletterPlugin::getTaskDefinitions())
            ->firstWhere('key', 'newsletter_extract_content');

        $this->assertNull($event->fresh()->issueContent());
        $this->assertNull($event->fresh()->load('blocks')->issueContent());
        $this->assertTrue($definition->isApplicableTo($event->fresh()));
    }

    #[Test]
    public function an_issue_extracted_before_per_issue_text_falls_back_to_the_publication(): void
    {
        [$event, $publication] = $this->newsletterEvent(rawHtml: '<html>Old</html>');
        $publication->update(['content' => '# Latest issue']);

        $this->assertNull($event->issueContent());
        $this->assertSame('# Latest issue', $event->displayTargetContent());
    }

    #[Test]
    public function a_new_issue_of_an_extracted_publication_is_still_extracted(): void
    {
        [$event] = $this->newsletterEvent(rawHtml: '<html>New issue</html>', extractedAt: now()->toIso8601String());

        $definition = collect(NewsletterPlugin::getTaskDefinitions())
            ->firstWhere('key', 'newsletter_extract_content');

        $this->assertTrue($definition->isApplicableTo($event));
    }

    #[Test]
    public function should_run_guard_skips_when_already_extracted(): void
    {
        [$event] = $this->newsletterEvent(rawHtml: '<html>Raw newsletter</html>');
        $event->createBlock([
            'title' => 'Issue Content',
            'block_type' => 'newsletter_content',
            'time' => $event->time,
            'metadata' => ['content' => '# Already extracted'],
        ]);

        $definition = collect(NewsletterPlugin::getTaskDefinitions())
            ->firstWhere('key', 'newsletter_extract_content');

        $this->assertFalse($definition->isApplicableTo($event));
    }

    #[Test]
    public function it_marks_failed_when_raw_html_is_missing(): void
    {
        [$event] = $this->newsletterEvent(rawHtml: null);

        $this->expectException(Exception::class);

        try {
            (new NewsletterExtractContentTask($event, $this->task()))->handle();
        } finally {
            $this->assertSame('failed', $event->refresh()->event_metadata['task_executions']['newsletter_extract_content']['last_attempt']['status']);
        }
    }

    private function newsletterEvent(?string $rawHtml, ?string $extractedAt = null): array
    {
        Queue::fake([ProcessTaskPipelineJob::class]);
        $integration = Integration::factory()->create(['service' => 'newsletter']);
        $publication = EventObject::factory()->create([
            'user_id' => $integration->user_id,
            'concept' => 'publication',
            'type' => 'newsletter_publication',
            'content' => null,
            'metadata' => array_filter(['extracted_at' => $extractedAt]),
        ]);

        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'target_id' => $publication->id,
            'service' => 'newsletter',
            'domain' => 'knowledge',
            'action' => 'received_post',
            'event_metadata' => array_filter([
                'email_subject' => 'Subject',
                'raw_html' => $rawHtml,
            ], fn ($value) => $value !== null),
        ]);

        return [$event, $publication];
    }

    private function task(): TaskDefinition
    {
        return new TaskDefinition(
            key: 'newsletter_extract_content',
            name: 'Newsletter: Extract Content',
            description: 'Extract clean Markdown from raw newsletter HTML using AI',
            jobClass: NewsletterExtractContentTask::class,
            appliesTo: ['event'],
        );
    }

    private function openAiResponse(string $content): CreateResponse
    {
        return CreateResponse::fake([
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => $content,
                    ],
                ],
            ],
        ]);
    }
}
