<?php

namespace Tests\Feature\TaskPipeline;

use App\Integrations\Fetch\FetchPlugin;
use App\Jobs\TaskPipeline\ProcessTaskPipelineJob;
use App\Jobs\TaskPipeline\Tasks\FetchExtractContentTask;
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

class FetchExtractContentTaskTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_extracts_fetch_content_and_marks_success(): void
    {
        Queue::fake([ProcessTaskPipelineJob::class]);
        $fake = OpenAI::fake([$this->openAiResponse('# Clean Article')]);

        [$event, $webpage] = $this->fetchEvent(webpageContent: null, blockText: 'Raw article text');

        (new FetchExtractContentTask($event, $this->task()))->handle();

        $this->assertSame('# Clean Article', $webpage->refresh()->content);
        $this->assertSame('success', $event->refresh()->event_metadata['task_executions']['fetch_extract_content']['last_attempt']['status']);
        Queue::assertPushed(ProcessTaskPipelineJob::class);
        $fake->assertSent(Chat::class, 1);
    }

    #[Test]
    public function it_is_applicable_even_when_webpage_content_is_pre_populated_with_excerpt(): void
    {
        // Regression: ProcessFetchedContent sets content = excerpt before the pipeline runs.
        // The task must still run so extraction replaces the excerpt with the full article.
        [$event] = $this->fetchEvent(webpageContent: 'Short excerpt, not yet extracted', blockText: 'Raw article text');

        $definition = collect(FetchPlugin::getTaskDefinitions())
            ->firstWhere('key', 'fetch_extract_content');

        $this->assertTrue($definition->isApplicableTo($event));
    }

    #[Test]
    public function it_is_not_applicable_when_no_fetch_content_block_exists(): void
    {
        Queue::fake([ProcessTaskPipelineJob::class]);
        $integration = Integration::factory()->create(['service' => 'fetch']);
        $webpage = EventObject::factory()->create([
            'user_id' => $integration->user_id,
            'concept' => 'webpage',
            'type' => 'fetch_webpage',
            'content' => null,
        ]);
        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'target_id' => $webpage->id,
            'service' => 'fetch',
            'domain' => 'knowledge',
            'action' => 'fetched',
        ]);

        $definition = collect(FetchPlugin::getTaskDefinitions())
            ->firstWhere('key', 'fetch_extract_content');

        $this->assertFalse($definition->isApplicableTo($event->fresh(['blocks', 'target'])));
    }

    #[Test]
    public function it_marks_failed_when_fetch_content_block_is_missing_text(): void
    {
        [$event] = $this->fetchEvent(webpageContent: null, blockText: '');

        $this->expectException(Exception::class);

        try {
            (new FetchExtractContentTask($event, $this->task()))->handle();
        } finally {
            $this->assertSame('failed', $event->refresh()->event_metadata['task_executions']['fetch_extract_content']['last_attempt']['status']);
        }
    }

    private function fetchEvent(?string $webpageContent, string $blockText): array
    {
        Queue::fake([ProcessTaskPipelineJob::class]);
        $integration = Integration::factory()->create(['service' => 'fetch']);
        $webpage = EventObject::factory()->create([
            'user_id' => $integration->user_id,
            'concept' => 'webpage',
            'type' => 'fetch_webpage',
            'title' => 'Article Title',
            'content' => $webpageContent,
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
                'text' => $blockText,
                'excerpt' => 'Excerpt',
            ],
        ]);

        return [$event->fresh(['blocks', 'target']), $webpage];
    }

    private function task(): TaskDefinition
    {
        return new TaskDefinition(
            key: 'fetch_extract_content',
            name: 'Fetch: Extract Content',
            description: 'Extract clean Markdown from fetched article HTML using AI',
            jobClass: FetchExtractContentTask::class,
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
