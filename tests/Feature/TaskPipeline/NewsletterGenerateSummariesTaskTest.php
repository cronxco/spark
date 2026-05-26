<?php

namespace Tests\Feature\TaskPipeline;

use App\Integrations\Newsletter\NewsletterPlugin;
use App\Jobs\TaskPipeline\ProcessTaskPipelineJob;
use App\Jobs\TaskPipeline\Tasks\NewsletterGenerateSummariesTask;
use App\Models\Block;
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

class NewsletterGenerateSummariesTaskTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_generates_newsletter_summary_blocks_and_marks_success(): void
    {
        $fake = OpenAI::fake([$this->openAiResponse(json_encode($this->summaryPayload()))]);

        [$event] = $this->newsletterEvent(content: 'Clean article text');

        (new NewsletterGenerateSummariesTask($event, $this->task()))->handle();

        $this->assertSame(5, $event->blocks()->whereIn('block_type', [
            'newsletter_summary_tweet',
            'newsletter_summary_short',
            'newsletter_summary_paragraph',
            'newsletter_key_takeaways',
            'newsletter_tldr',
        ])->count());
        $this->assertSame('success', $event->refresh()->event_metadata['task_executions']['newsletter_generate_summaries']['last_attempt']['status']);
        $fake->assertSent(Chat::class, 1);
    }

    #[Test]
    public function should_run_guard_skips_when_tldr_already_exists(): void
    {
        [$event] = $this->newsletterEvent(content: 'Clean article text');
        Block::factory()->create([
            'event_id' => $event->id,
            'block_type' => 'newsletter_tldr',
            'title' => 'TL;DR',
            'metadata' => ['content' => 'Already summarized'],
        ]);

        $definition = collect(NewsletterPlugin::getTaskDefinitions())
            ->firstWhere('key', 'newsletter_generate_summaries');

        $this->assertFalse($definition->isApplicableTo($event));
    }

    #[Test]
    public function it_marks_failed_when_content_is_missing(): void
    {
        [$event] = $this->newsletterEvent(content: null);

        $this->expectException(Exception::class);

        try {
            (new NewsletterGenerateSummariesTask($event, $this->task()))->handle();
        } finally {
            $this->assertSame('failed', $event->refresh()->event_metadata['task_executions']['newsletter_generate_summaries']['last_attempt']['status']);
        }
    }

    private function newsletterEvent(?string $content): array
    {
        Queue::fake([ProcessTaskPipelineJob::class]);
        $integration = Integration::factory()->create(['service' => 'newsletter']);
        $publication = EventObject::factory()->create([
            'user_id' => $integration->user_id,
            'concept' => 'publication',
            'type' => 'newsletter_publication',
            'content' => $content,
        ]);

        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'target_id' => $publication->id,
            'service' => 'newsletter',
            'domain' => 'knowledge',
            'action' => 'received_post',
            'event_metadata' => ['email_subject' => 'Subject'],
        ]);

        return [$event, $publication];
    }

    private function task(): TaskDefinition
    {
        return new TaskDefinition(
            key: 'newsletter_generate_summaries',
            name: 'Newsletter: Generate Summaries',
            description: 'Generate AI summary blocks and tags for a newsletter event',
            jobClass: NewsletterGenerateSummariesTask::class,
            appliesTo: ['event'],
        );
    }

    private function summaryPayload(): array
    {
        return [
            'summary_tweet' => 'Tweet',
            'summary_short' => 'Short summary',
            'summary_paragraph' => 'Paragraph summary',
            'key_takeaways' => ['One', 'Two', 'Three'],
            'tldr' => 'TLDR',
            'emoji' => '',
            'tags' => [],
        ];
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
