<?php

namespace Tests\Feature\TaskPipeline;

use App\Integrations\Fetch\FetchPlugin;
use App\Jobs\TaskPipeline\ProcessTaskPipelineJob;
use App\Jobs\TaskPipeline\Tasks\FetchGenerateSummariesTask;
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

class FetchGenerateSummariesTaskTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_generates_fetch_summary_blocks_and_marks_success(): void
    {
        $fake = OpenAI::fake([$this->openAiResponse(json_encode($this->summaryPayload()))]);

        [$event, $webpage] = $this->fetchEvent(content: 'Clean article text');

        (new FetchGenerateSummariesTask($event, $this->task()))->handle();

        $this->assertSame(5, $event->blocks()->whereIn('block_type', [
            'fetch_summary_tweet',
            'fetch_summary_short',
            'fetch_summary_paragraph',
            'fetch_key_takeaways',
            'fetch_tldr',
        ])->count());
        $this->assertNotEmpty($webpage->refresh()->metadata['extracted_at']);
        $this->assertSame('success', $event->refresh()->event_metadata['task_executions']['fetch_generate_summaries']['last_attempt']['status']);
        $fake->assertSent(Chat::class, 1);
    }

    #[Test]
    public function should_run_guard_skips_when_tldr_already_exists(): void
    {
        [$event] = $this->fetchEvent(content: 'Clean article text');
        Block::factory()->create([
            'event_id' => $event->id,
            'block_type' => 'fetch_tldr',
            'title' => 'TL;DR',
            'metadata' => ['content' => 'Already summarized'],
        ]);

        $definition = collect(FetchPlugin::getTaskDefinitions())
            ->firstWhere('key', 'fetch_generate_summaries');

        $this->assertFalse($definition->isApplicableTo($event));
    }

    #[Test]
    public function it_marks_failed_when_content_is_missing(): void
    {
        [$event] = $this->fetchEvent(content: null);

        $this->expectException(Exception::class);

        try {
            (new FetchGenerateSummariesTask($event, $this->task()))->handle();
        } finally {
            $this->assertSame('failed', $event->refresh()->event_metadata['task_executions']['fetch_generate_summaries']['last_attempt']['status']);
        }
    }

    private function fetchEvent(?string $content): array
    {
        Queue::fake([ProcessTaskPipelineJob::class]);
        $integration = Integration::factory()->create(['service' => 'fetch']);
        $webpage = EventObject::factory()->create([
            'user_id' => $integration->user_id,
            'concept' => 'webpage',
            'type' => 'fetch_webpage',
            'title' => 'Article Title',
            'content' => $content,
            'metadata' => ['author' => 'Author', 'direction' => 'ltr'],
        ]);

        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'target_id' => $webpage->id,
            'service' => 'fetch',
            'domain' => 'knowledge',
            'action' => 'fetched',
        ]);

        Block::factory()->create([
            'event_id' => $event->id,
            'block_type' => 'fetch_content',
            'title' => 'Raw Content',
            'metadata' => [
                'html' => '<article>Raw article text</article>',
                'text' => 'Raw article text',
                'excerpt' => 'Excerpt',
            ],
        ]);

        return [$event->fresh(['blocks', 'target']), $webpage];
    }

    private function task(): TaskDefinition
    {
        return new TaskDefinition(
            key: 'fetch_generate_summaries',
            name: 'Fetch: Generate Summaries',
            description: 'Generate AI summary blocks and tags for a fetched article',
            jobClass: FetchGenerateSummariesTask::class,
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
