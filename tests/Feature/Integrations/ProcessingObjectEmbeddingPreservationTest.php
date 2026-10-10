<?php

namespace Tests\Feature\Integrations;

use App\Jobs\Base\BaseProcessingJob;
use App\Models\EventObject;
use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessingObjectEmbeddingPreservationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function object_refresh_preserves_vector_when_provider_omits_or_returns_null_embedding(): void
    {
        Queue::fake();
        $integration = Integration::factory()->create(['service' => 'test']);
        $object = EventObject::withoutEvents(fn () => EventObject::factory()->withEmbeddings()->create([
            'user_id' => $integration->user_id,
            'concept' => 'document',
            'type' => 'test_document',
            'title' => 'Unchanged document',
        ]));
        $original = $object->fresh()->getRawOriginal('embeddings');
        $data = [
            'concept' => $object->concept,
            'type' => $object->type,
            'title' => $object->title,
            'time' => $object->time,
            'content' => $object->content,
            'metadata' => ['revision' => 2],
        ];

        $job = $this->processingJob($integration);
        $updated = $job->upsertObject($data);
        $this->assertSame($object->id, $updated->id);
        $this->assertSame($original, $updated->fresh()->getRawOriginal('embeddings'));
        $this->assertSame(2, $updated->fresh()->metadata['revision']);

        $updated = $job->upsertObject([...$data, 'embeddings' => null]);
        $this->assertSame($original, $updated->fresh()->getRawOriginal('embeddings'));
    }

    #[Test]
    public function explicit_vector_is_used_on_creation_and_refresh(): void
    {
        Queue::fake();
        $integration = Integration::factory()->create(['service' => 'test']);
        $job = $this->processingJob($integration);
        $data = ['concept' => 'document', 'type' => 'test_document', 'title' => 'New document'];
        $first = array_fill(0, 1536, 0.25);
        $second = array_fill(0, 1536, 0.5);

        $object = $job->upsertObject([...$data, 'embeddings' => $first]);
        $original = $object->fresh()->getRawOriginal('embeddings');
        $this->assertNotNull($original);

        $updated = $job->upsertObject([...$data, 'embeddings' => $second]);
        $this->assertSame($object->id, $updated->id);
        $this->assertNotSame($original, $updated->fresh()->getRawOriginal('embeddings'));
    }

    private function processingJob(Integration $integration): BaseProcessingJob
    {
        return new class($integration, []) extends BaseProcessingJob
        {
            public function upsertObject(array $data): EventObject
            {
                return $this->createOrUpdateObject($data);
            }

            protected function getServiceName(): string
            {
                return 'test';
            }

            protected function getJobType(): string
            {
                return 'documents';
            }

            protected function process(): void {}
        };
    }
}
