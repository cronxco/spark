<?php

namespace Tests\Feature\TaskPipeline;

use App\Jobs\TaskPipeline\Tasks\GenerateEmbeddingTask;
use App\Models\Event;
use App\Services\Ai\EmbeddingClient;
use App\Services\TaskPipeline\TaskDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class GenerateEmbeddingTaskTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function stores_successful_embedding_and_marks_task_successful(): void
    {
        $event = Event::factory()->create([
            'action' => 'paid',
            'domain' => 'money',
            'service' => 'monzo',
        ]);

        $embedding = $this->validEmbedding();
        $embeddingService = Mockery::mock(EmbeddingClient::class);
        $embeddingService->shouldReceive('embed')->once()->andReturn($embedding);
        $embeddingService->shouldReceive('getEmbeddingMetadata')->once()->andReturn([
            'embedding_model' => 'text-embedding-3-small',
            'embedding_dimensions' => 1536,
            'embedding_generated_at' => now()->toIso8601String(),
        ]);
        $this->instance(EmbeddingClient::class, $embeddingService);

        (new GenerateEmbeddingTask($event, $this->taskDefinition()))->handle();

        $event->refresh();

        $this->assertNotNull($event->embeddings);
        $this->assertSame('text-embedding-3-small', $event->event_metadata['embedding_model']);
        $this->assertSame('success', $event->event_metadata['task_executions']['generate_embedding']['last_attempt']['status']);
        $this->assertSame('success', $event->event_metadata['task_executions']['generate_embedding']['last_success']['status']);
    }

    #[Test]
    public function provider_failure_marks_task_failed_and_does_not_store_embedding(): void
    {
        $event = Event::factory()->create();

        $embeddingService = Mockery::mock(EmbeddingClient::class);
        $embeddingService->shouldReceive('embed')->once()->andThrow(new RuntimeException('provider unavailable'));
        $embeddingService->shouldReceive('getEmbeddingMetadata')->never();
        $this->instance(EmbeddingClient::class, $embeddingService);

        try {
            (new GenerateEmbeddingTask($event, $this->taskDefinition()))->handle();
            $this->fail('Expected embedding task to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('provider unavailable', $exception->getMessage());
        }

        $event->refresh();

        $this->assertNull($event->embeddings);
        $this->assertSame('failed', $event->event_metadata['task_executions']['generate_embedding']['last_attempt']['status']);
        $this->assertSame('provider unavailable', $event->event_metadata['task_executions']['generate_embedding']['last_attempt']['error']);
        $this->assertArrayNotHasKey('last_success', $event->event_metadata['task_executions']['generate_embedding']);
    }

    #[Test]
    public function zero_vector_marks_task_failed_and_does_not_store_embedding(): void
    {
        $event = Event::factory()->create();

        $embeddingService = Mockery::mock(EmbeddingClient::class);
        $embeddingService->shouldReceive('embed')->once()->andReturn(array_fill(0, 1536, 0.0));
        $embeddingService->shouldReceive('getEmbeddingMetadata')->never();
        $this->instance(EmbeddingClient::class, $embeddingService);

        try {
            (new GenerateEmbeddingTask($event, $this->taskDefinition()))->handle();
            $this->fail('Expected embedding task to reject zero vector.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Embedding provider returned a zero vector', $exception->getMessage());
        }

        $event->refresh();

        $this->assertNull($event->embeddings);
        $this->assertSame('failed', $event->event_metadata['task_executions']['generate_embedding']['last_attempt']['status']);
        $this->assertSame(
            'Embedding provider returned a zero vector',
            $event->event_metadata['task_executions']['generate_embedding']['last_attempt']['error']
        );
        $this->assertArrayNotHasKey('last_success', $event->event_metadata['task_executions']['generate_embedding']);
    }

    private function taskDefinition(): TaskDefinition
    {
        return new TaskDefinition(
            key: 'generate_embedding',
            name: 'Generate Embedding',
            description: 'Generate AI embedding for semantic search',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['event'],
        );
    }

    private function validEmbedding(): array
    {
        $embedding = array_fill(0, 1536, 0.0);
        $embedding[0] = 0.25;

        return $embedding;
    }
}
