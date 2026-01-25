<?php

namespace Tests\Unit\Models;

use App\Models\Block;
use App\Models\Event;
use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BlockBatchCreationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function it_creates_blocks_in_batch_without_n_plus_one()
    {
        $integration = Integration::factory()->create();
        $event = Event::factory()->create([
            'integration_id' => $integration->id,
        ]);

        $blocksData = [
            ['title' => 'Heart Rate', 'block_type' => 'hr', 'value' => 75],
            ['title' => 'Calories', 'block_type' => 'cal', 'value' => 250],
            ['title' => 'Steps', 'block_type' => 'steps', 'value' => 5000],
        ];

        DB::enableQueryLog();
        $blocks = $event->createBlocksInBatch($blocksData);
        $queries = DB::getQueryLog();

        // Should have: 1 pre-load query + 3 inserts = 4 queries max
        // Without batching: Would be 3 existence checks + 3 inserts = 6 queries minimum
        $this->assertLessThan(6, count($queries), 'Expected fewer than 6 queries with batching, got ' . count($queries));
        $this->assertCount(3, $blocks);

        // Verify blocks were created
        $this->assertEquals(3, $event->blocks()->count());
        $this->assertNotNull($event->blocks()->where('title', 'Heart Rate')->first());
        $this->assertNotNull($event->blocks()->where('title', 'Calories')->first());
        $this->assertNotNull($event->blocks()->where('title', 'Steps')->first());
    }

    /**
     * @test
     */
    public function it_updates_existing_blocks_in_batch()
    {
        $integration = Integration::factory()->create();
        $event = Event::factory()->create([
            'integration_id' => $integration->id,
        ]);

        // Create initial blocks
        $initialBlocksData = [
            ['title' => 'Heart Rate', 'block_type' => 'hr', 'value' => 75],
            ['title' => 'Calories', 'block_type' => 'cal', 'value' => 250],
        ];
        $event->createBlocksInBatch($initialBlocksData);

        $this->assertEquals(2, $event->blocks()->count());
        $this->assertEquals(75, $event->blocks()->where('title', 'Heart Rate')->first()->value);

        // Update existing blocks
        $updatedBlocksData = [
            ['title' => 'Heart Rate', 'block_type' => 'hr', 'value' => 80], // Updated value
            ['title' => 'Calories', 'block_type' => 'cal', 'value' => 300], // Updated value
            ['title' => 'Steps', 'block_type' => 'steps', 'value' => 5000], // New block
        ];

        DB::enableQueryLog();
        $blocks = $event->createBlocksInBatch($updatedBlocksData);
        $queries = DB::getQueryLog();

        // Should be efficient even with updates (1 load + 2 updates + 1 insert + overhead)
        $this->assertLessThanOrEqual(8, count($queries));
        $this->assertCount(3, $blocks);

        // Verify blocks were updated
        $event->refresh();
        $this->assertEquals(3, $event->blocks()->count());
        $this->assertEquals(80, $event->blocks()->where('title', 'Heart Rate')->first()->value);
        $this->assertEquals(300, $event->blocks()->where('title', 'Calories')->first()->value);
        $this->assertEquals(5000, $event->blocks()->where('title', 'Steps')->first()->value);
    }

    /**
     * @test
     */
    public function it_handles_empty_blocks_data_gracefully()
    {
        $integration = Integration::factory()->create();
        $event = Event::factory()->create([
            'integration_id' => $integration->id,
        ]);

        $blocks = $event->createBlocksInBatch([]);

        $this->assertCount(0, $blocks);
        $this->assertEquals(0, $event->blocks()->count());
    }
}
