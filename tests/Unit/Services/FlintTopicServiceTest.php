<?php

namespace Tests\Unit\Services;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use App\Services\FlintTopicService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The read/write surface the Flint web UI uses directly, alongside the MCP tool
 * covered by {@see \Tests\Feature\Mcp\ManageFlintTopicToolTest}.
 */
class FlintTopicServiceTest extends TestCase
{
    use RefreshDatabase;

    private FlintTopicService $service;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(FlintTopicService::class);
        $this->user = User::factory()->create();
    }

    private function topic(string $title, array $metadata = []): EventObject
    {
        return EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'flint',
            'type' => 'topic',
            'title' => $title,
            'metadata' => array_merge(['kind' => 'thematic', 'status' => 'active'], $metadata),
        ]);
    }

    #[Test]
    public function query_returns_only_the_users_topics(): void
    {
        $this->topic('Mine');

        $other = User::factory()->create();
        EventObject::factory()->create([
            'user_id' => $other->id,
            'concept' => 'flint',
            'type' => 'topic',
            'title' => 'Theirs',
            'metadata' => ['kind' => 'thematic', 'status' => 'active'],
        ]);

        $this->assertSame(['Mine'], $this->service->query($this->user)->pluck('title')->all());
    }

    #[Test]
    public function query_ignores_objects_that_are_not_flint_topics(): void
    {
        $this->topic('A topic');
        EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'day',
            'type' => 'day',
            'title' => 'A day',
        ]);

        $this->assertSame(['A topic'], $this->service->query($this->user)->pluck('title')->all());
    }

    #[Test]
    public function query_filters_by_status_and_kind(): void
    {
        $this->topic('Active thematic', ['status' => 'active', 'kind' => 'thematic']);
        $this->topic('Dormant thematic', ['status' => 'dormant', 'kind' => 'thematic']);
        $this->topic('Active strategic', ['status' => 'active', 'kind' => 'strategic']);

        $this->assertSame(
            ['Active strategic', 'Active thematic'],
            $this->service->query($this->user, 'active')->orderBy('title')->pluck('title')->all(),
        );

        $this->assertSame(
            ['Active thematic'],
            $this->service->query($this->user, 'active', 'thematic')->pluck('title')->all(),
        );
    }

    #[Test]
    public function status_counts_group_topics_by_status(): void
    {
        $this->topic('One', ['status' => 'active']);
        $this->topic('Two', ['status' => 'active']);
        $this->topic('Three', ['status' => 'resolved']);

        $counts = $this->service->statusCounts($this->user);

        $this->assertSame(2, $counts['active']);
        $this->assertSame(1, $counts['resolved']);
        $this->assertArrayNotHasKey('dormant', $counts);
    }

    #[Test]
    public function mentions_returns_linked_events_and_blocks_newest_first(): void
    {
        $topic = $this->topic('Canada trip 2027');
        $integration = Integration::factory()->create(['user_id' => $this->user->id, 'service' => 'flint']);

        $older = Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'flint',
            'action' => 'had_summary',
            'time' => Carbon::parse('2026-06-01'),
            'event_metadata' => ['title' => 'Older digest', 'period' => 'morning'],
        ]);
        $newer = Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'flint',
            'action' => 'had_summary',
            'time' => Carbon::parse('2026-06-10'),
            'event_metadata' => ['title' => 'Newer digest', 'period' => 'evening'],
        ]);
        $block = Block::factory()->create([
            'event_id' => $newer->id,
            'block_type' => 'flint_editorial_note',
            'title' => 'A note about Canada',
            'time' => Carbon::parse('2026-06-05'),
        ]);

        foreach ([$older, $newer] as $event) {
            $this->service->update($this->user, $topic->id, ['related_event_id' => $event->id]);
        }
        $this->service->update($this->user, $topic->id, ['related_block_id' => $block->id]);

        $mentions = $this->service->mentions($topic);

        $this->assertSame(
            ['Newer digest', 'A note about Canada', 'Older digest'],
            $mentions->pluck('title')->all(),
        );
        $this->assertSame(['event', 'block', 'event'], $mentions->pluck('kind')->all());
    }

    #[Test]
    public function mentions_is_empty_for_an_untouched_topic(): void
    {
        $this->assertTrue($this->service->mentions($this->topic('Lonely'))->isEmpty());
    }

    #[Test]
    public function delete_removes_an_owned_topic(): void
    {
        $topic = $this->topic('Done with this');

        $this->assertTrue($this->service->delete($this->user, $topic->id));
        $this->assertSoftDeleted('objects', ['id' => $topic->id]);
    }

    #[Test]
    public function delete_refuses_another_users_topic(): void
    {
        $other = User::factory()->create();
        $topic = EventObject::factory()->create([
            'user_id' => $other->id,
            'concept' => 'flint',
            'type' => 'topic',
            'title' => 'Not yours',
            'metadata' => ['kind' => 'thematic', 'status' => 'active'],
        ]);

        $this->assertFalse($this->service->delete($this->user, $topic->id));
        $this->assertNotSoftDeleted('objects', ['id' => $topic->id]);
    }

    #[Test]
    public function update_only_touches_the_fields_it_is_given(): void
    {
        $topic = $this->topic('Keep my content', ['kind' => 'strategic', 'status' => 'active']);
        $topic->update(['content' => 'Original understanding.']);

        $this->service->update($this->user, $topic->id, ['status' => 'dormant']);

        $topic->refresh();

        $this->assertSame('Keep my content', $topic->title);
        $this->assertSame('Original understanding.', $topic->content);
        $this->assertSame('strategic', $topic->metadata['kind']);
        $this->assertSame('dormant', $topic->metadata['status']);
        $this->assertNotNull($topic->metadata['last_touched_at']);
    }
}
