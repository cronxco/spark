<?php

namespace Tests\Feature\Commands;

use App\Jobs\GenerateEventEmbeddingJob;
use App\Models\Event;
use App\Models\Integration;
use App\Services\Ai\EmbeddingClient;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RegenerateEmbeddingsStaleModelTest extends TestCase
{
    #[Test]
    public function it_only_requeues_rows_embedded_with_a_different_model(): void
    {
        config(['services.openai.models.embedding' => 'text-embedding-3-large']);
        Queue::fake();

        $stale = $this->eventEmbeddedWith('text-embedding-3-small');
        $current = $this->eventEmbeddedWith('text-embedding-3-large');

        $this->artisan('embeddings:regenerate', ['--model' => 'Event', '--stale-model' => true])
            ->assertSuccessful();

        Queue::assertPushed(GenerateEventEmbeddingJob::class, 1);
        Queue::assertPushed(
            GenerateEventEmbeddingJob::class,
            fn (GenerateEventEmbeddingJob $job) => $job->event->is($stale) && $job->bypassCache
        );
        Queue::assertNotPushed(
            GenerateEventEmbeddingJob::class,
            fn (GenerateEventEmbeddingJob $job) => $job->event->is($current)
        );
    }

    #[Test]
    public function it_treats_a_row_with_no_stamped_model_as_stale(): void
    {
        config(['services.openai.models.embedding' => 'text-embedding-3-small']);
        Queue::fake();

        $unstamped = $this->eventEmbeddedWith(null);

        $this->artisan('embeddings:regenerate', ['--model' => 'Event', '--stale-model' => true])
            ->assertSuccessful();

        Queue::assertPushed(
            GenerateEventEmbeddingJob::class,
            fn (GenerateEventEmbeddingJob $job) => $job->event->is($unstamped)
        );
    }

    #[Test]
    public function it_ignores_rows_that_have_no_embedding_yet(): void
    {
        config(['services.openai.models.embedding' => 'text-embedding-3-small']);
        Queue::fake();

        $integration = Integration::factory()->create(['service' => 'fetch']);
        Event::factory()->create([
            'integration_id' => $integration->id,
            'embeddings' => null,
            'event_metadata' => [],
        ]);

        $this->artisan('embeddings:regenerate', ['--model' => 'Event', '--stale-model' => true])
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    private function eventEmbeddedWith(?string $model): Event
    {
        $integration = Integration::factory()->create(['service' => 'fetch']);

        return Event::factory()->create([
            'integration_id' => $integration->id,
            'event_metadata' => $model === null ? [] : ['embedding_model' => $model],
            'embeddings' => EmbeddingClient::formatForPostgres(array_fill(0, 1536, 0.1)),
        ]);
    }
}
