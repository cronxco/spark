<?php

namespace Tests\Unit\Jobs;

use App\Jobs\Base\BaseProcessingJob;
use App\Models\Event;
use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TagBatchAttachmentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function it_batch_attaches_tags_without_n_plus_one()
    {
        $integration = Integration::factory()->create();

        // Create 10 events with 3 tags each
        $eventData = [];
        for ($i = 0; $i < 10; $i++) {
            $eventData[] = [
                'source_id' => 'test_event_' . $i,
                'time' => now()->subHours($i),
                'domain' => 'test',
                'action' => 'test_action',
                'actor' => [
                    'concept' => 'user',
                    'type' => 'test_user',
                    'title' => 'Test User ' . $i,
                ],
                'target' => [
                    'concept' => 'object',
                    'type' => 'test_object',
                    'title' => 'Test Object ' . $i,
                ],
                'tags' => ['tag1', 'tag2', 'tag3'],
            ];
        }

        $job = new class($integration, $eventData) extends BaseProcessingJob
        {
            protected function getServiceName(): string
            {
                return 'test';
            }

            protected function getJobType(): string
            {
                return 'tag_batch_test';
            }

            protected function process(): void
            {
                $this->createEvents($this->rawData);
            }
        };

        // Enable query logging
        DB::enableQueryLog();

        // Execute the job
        $job->handle();

        // Get query log
        $queries = DB::getQueryLog();

        // Filter for tag-related queries
        $tagQueries = collect($queries)->filter(function ($query) {
            return str_contains($query['query'], 'tags') ||
                   str_contains($query['query'], 'taggables');
        })->count();

        // With batching: Should be ~20-35 tag queries (3 tag creates + 10 syncs + overhead)
        // Without batching: Would be 60+ queries (10 events × 3 tags × 2 queries each)
        $this->assertLessThan(50, $tagQueries, "Expected fewer than 50 tag queries with batching, got {$tagQueries}");

        // Verify all events were created with tags
        $createdEvents = Event::where('integration_id', $integration->id)->get();
        $this->assertEquals(10, $createdEvents->count());

        foreach ($createdEvents as $event) {
            $this->assertEquals(3, $event->tags()->count(), "Event {$event->source_id} should have 3 tags");
            $this->assertTrue($event->tags->pluck('name')->contains('tag1'));
            $this->assertTrue($event->tags->pluck('name')->contains('tag2'));
            $this->assertTrue($event->tags->pluck('name')->contains('tag3'));
        }
    }

    /**
     * @test
     */
    public function it_handles_mixed_tag_types()
    {
        $integration = Integration::factory()->create();

        $eventData = [
            [
                'source_id' => 'test_event_1',
                'time' => now(),
                'domain' => 'test',
                'action' => 'test_action',
                'actor' => ['concept' => 'user', 'type' => 'test', 'title' => 'User'],
                'target' => ['concept' => 'object', 'type' => 'test', 'title' => 'Object'],
                'tags' => [
                    'simple_tag',
                    ['name' => 'typed_tag', 'type' => 'custom_type'],
                ],
            ],
        ];

        $job = new class($integration, $eventData) extends BaseProcessingJob
        {
            protected function getServiceName(): string
            {
                return 'test';
            }

            protected function getJobType(): string
            {
                return 'tag_type_test';
            }

            protected function process(): void
            {
                $this->createEvents($this->rawData);
            }
        };

        $job->handle();

        $event = Event::where('integration_id', $integration->id)->first();
        $this->assertEquals(2, $event->tags()->count());

        // Verify tag types
        $simpleTags = $event->tags()->where('type', 'spark_tag')->get();
        $customTags = $event->tags()->where('type', 'custom_type')->get();

        $this->assertEquals(1, $simpleTags->count());
        $this->assertEquals(1, $customTags->count());
        $this->assertEquals('simple_tag', $simpleTags->first()->name);
        $this->assertEquals('typed_tag', $customTags->first()->name);
    }
}
