<?php

namespace Tests\Feature\Models;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PreservesPipelineMetadataTest extends TestCase
{
    #[Test]
    public function a_sync_rewrite_keeps_pipeline_keys_on_blocks(): void
    {
        $block = Block::factory()->create([
            'event_id' => Event::factory()->create()->id,
            'metadata' => ['status' => 'confirmed', 'description' => 'Old'] + $this->pipelineKeys(),
        ]);
        $block = Block::query()->findOrFail($block->id);

        $block->update(['metadata' => ['status' => 'confirmed', 'description' => 'New']]);

        $metadata = $block->fresh()->metadata;
        $this->assertSame('New', $metadata['description']);
        $this->assertSame('text-embedding-3-small', $metadata['embedding_model']);
        $this->assertArrayHasKey('task_executions', $metadata);
    }

    #[Test]
    public function an_identical_rewrite_is_not_a_change_on_any_model(): void
    {
        $event = Event::factory()->create([
            'event_metadata' => ['location' => 'Office', 'status' => 'confirmed'] + $this->pipelineKeys(),
        ]);
        $object = EventObject::factory()->create([
            'metadata' => ['source_id' => 'abc', 'kind' => 'doc'] + $this->pipelineKeys(),
        ]);
        $event = Event::query()->findOrFail($event->id);
        $object = EventObject::query()->findOrFail($object->id);

        $event->fill(['event_metadata' => ['status' => 'confirmed', 'location' => 'Office']])->save();
        $object->fill(['metadata' => ['kind' => 'doc', 'source_id' => 'abc']])->save();

        $this->assertFalse($event->wasChanged('event_metadata'));
        $this->assertFalse($object->wasChanged('metadata'));
        $this->assertSame('text-embedding-3-small', $event->fresh()->event_metadata['embedding_model']);
        $this->assertSame('text-embedding-3-small', $object->fresh()->metadata['embedding_model']);
    }

    #[Test]
    public function an_explicit_change_to_pipeline_keys_is_saved(): void
    {
        $event = Event::factory()->create([
            'event_metadata' => ['status' => 'confirmed'] + $this->pipelineKeys(),
        ]);
        $event = Event::query()->findOrFail($event->id);

        $event->update(['event_metadata' => ['task_executions' => ['other' => ['last_attempt' => ['status' => 'failed']]]]]);

        $this->assertSame(
            ['other'],
            array_keys($event->fresh()->event_metadata['task_executions'])
        );
    }

    #[Test]
    public function a_real_change_is_still_saved(): void
    {
        $object = EventObject::factory()->create([
            'metadata' => ['kind' => 'doc'] + $this->pipelineKeys(),
        ]);
        $object = EventObject::query()->findOrFail($object->id);

        $object->update(['metadata' => ['kind' => 'note']]);

        $this->assertTrue($object->wasChanged('metadata'));
        $this->assertSame('note', $object->fresh()->metadata['kind']);
    }

    private function pipelineKeys(): array
    {
        return [
            'embedding_model' => 'text-embedding-3-small',
            'task_executions' => ['generate_embedding' => ['last_attempt' => ['status' => 'success']]],
        ];
    }
}
