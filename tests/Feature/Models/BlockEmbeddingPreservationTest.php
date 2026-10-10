<?php

namespace Tests\Feature\Models;

use App\Models\Block;
use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BlockEmbeddingPreservationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function refreshing_a_block_without_a_vector_keeps_its_embedding(): void
    {
        Queue::fake();
        $event = Event::factory()->create();
        $block = $event->createBlock(['title' => 'Day note', 'block_type' => 'outline_content', 'metadata' => ['v' => 1]]);
        $vector = '[' . implode(',', array_fill(0, 1536, 0.25)) . ']';
        DB::table($block->getTable())->where('id', $block->id)->update(['embeddings' => $vector]);
        $original = $block->fresh()->getRawOriginal('embeddings');
        $this->assertNotNull($original);

        $event->createBlock(['title' => 'Day note', 'block_type' => 'outline_content', 'metadata' => ['v' => 2], 'embeddings' => null]);

        $fresh = $block->fresh();
        $this->assertSame(2, $fresh->metadata['v']);
        $this->assertSame($original, $fresh->getRawOriginal('embeddings'));
    }

    #[Test]
    public function only_null_embeddings_are_dropped(): void
    {
        $this->assertSame(['title' => 'x'], Block::withoutNullEmbeddings(['title' => 'x', 'embeddings' => null]));
        $this->assertSame(['embeddings' => '[1]'], Block::withoutNullEmbeddings(['embeddings' => '[1]']));
    }
}
