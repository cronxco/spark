<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\SparkServer;
use App\Mcp\Tools\ManageFlintTopicTool;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use App\Services\Flint\FlintRunToken;
use App\Services\FlintTopicTaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ManageFlintTopicToolTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    #[Test]
    public function it_creates_a_topic_with_its_metadata(): void
    {
        $response = SparkServer::actingAs($this->user)->tool(ManageFlintTopicTool::class, [
            'operation' => 'create',
            'title' => 'Canada trip 2027',
            'content' => 'A long-term trip worth planning deliberately.',
            'kind' => 'strategic',
            'next_review_at' => '2027-03-01',
            'origin' => 'conversation',
        ]);

        $response->assertOk();
        $response->assertSee('Canada trip 2027');
        $this->assertDatabaseHas('objects', [
            'user_id' => $this->user->id,
            'concept' => 'flint',
            'type' => 'topic',
            'title' => 'Canada trip 2027',
        ]);
    }

    #[Test]
    public function it_partially_updates_a_topic(): void
    {
        SparkServer::actingAs($this->user)->tool(ManageFlintTopicTool::class, [
            'operation' => 'create', 'title' => '5K goal', 'kind' => 'thematic',
        ]);
        $topic = EventObject::where('title', '5K goal')->firstOrFail();

        $response = SparkServer::actingAs($this->user)->tool(ManageFlintTopicTool::class, [
            'operation' => 'update',
            'id' => $topic->id,
            'content' => 'Training is progressing with a focus on consistency.',
            'status' => 'dormant',
        ]);

        $response->assertOk();
        $topic->refresh();
        $this->assertSame('Training is progressing with a focus on consistency.', $topic->content);
        $this->assertSame('thematic', $topic->metadata['kind']);
        $this->assertSame('dormant', $topic->metadata['status']);
        $this->assertNotEmpty($topic->metadata['last_touched_at']);
    }

    #[Test]
    public function it_filters_topics_and_scopes_them_to_the_authenticated_user(): void
    {
        SparkServer::actingAs($this->user)->tool(ManageFlintTopicTool::class, [
            'operation' => 'create', 'title' => 'Active fitness', 'kind' => 'thematic', 'status' => 'active',
        ]);
        SparkServer::actingAs($this->user)->tool(ManageFlintTopicTool::class, [
            'operation' => 'create', 'title' => 'Dormant trip', 'kind' => 'strategic', 'status' => 'dormant',
        ]);
        $otherUser = User::factory()->create();
        SparkServer::actingAs($otherUser)->tool(ManageFlintTopicTool::class, [
            'operation' => 'create', 'title' => 'Private topic', 'kind' => 'tactical',
        ]);

        $response = SparkServer::actingAs($this->user)->tool(ManageFlintTopicTool::class, [
            'operation' => 'list', 'status' => 'active',
        ]);

        $response->assertOk();
        $response->assertSee('Active fitness');
        $response->assertDontSee('Dormant trip');
        $response->assertDontSee('Private topic');
    }

    #[Test]
    public function it_links_owned_events_and_blocks_as_topic_mentions(): void
    {
        $integration = Integration::factory()->create(['user_id' => $this->user->id, 'service' => 'flint']);
        $event = Event::factory()->create(['integration_id' => $integration->id]);
        $block = Block::factory()->create(['event_id' => $event->id]);

        $response = SparkServer::actingAs($this->user)->tool(ManageFlintTopicTool::class, [
            'operation' => 'create',
            'title' => 'Ongoing story',
            'kind' => 'tactical',
            'related_event_id' => $event->id,
            'related_block_id' => $block->id,
        ]);

        $response->assertOk();
        $topic = EventObject::where('title', 'Ongoing story')->firstOrFail();
        $this->assertTrue($topic->relatedEvents('discussed_in')->whereKey($event->id)->exists());
        $this->assertTrue($topic->relatedBlocks('discussed_in')->whereKey($block->id)->exists());
    }

    #[Test]
    public function it_does_not_link_another_users_entities(): void
    {
        $otherUser = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $otherUser->id]);
        $event = Event::factory()->create(['integration_id' => $integration->id]);

        $response = SparkServer::actingAs($this->user)->tool(ManageFlintTopicTool::class, [
            'operation' => 'create', 'title' => 'Scoped topic', 'kind' => 'tactical', 'related_event_id' => $event->id,
        ]);

        $response->assertOk();
        $topic = EventObject::where('title', 'Scoped topic')->firstOrFail();
        $this->assertFalse($topic->relatedEvents('discussed_in')->exists());
    }

    #[Test]
    public function it_requires_authentication(): void
    {
        $response = SparkServer::tool(ManageFlintTopicTool::class, ['operation' => 'list']);

        $response->assertHasErrors(['Authentication required']);
    }

    #[Test]
    public function a_verified_topics_run_is_associated_with_its_persisted_output(): void
    {
        $runUuid = (string) Str::uuid();
        $token = app(FlintRunToken::class)->issue([
            'run_uuid' => $runUuid,
            'user_id' => (string) $this->user->id,
            'routine' => 'topics',
        ]);

        SparkServer::actingAs($this->user)->tool(ManageFlintTopicTool::class, [
            'operation' => 'create',
            'title' => 'Routine output',
            'kind' => 'tactical',
            'run_token' => $token,
        ])->assertOk();

        $topic = EventObject::where('title', 'Routine output')->firstOrFail();
        $this->assertContains($runUuid, $topic->metadata['run_uuids']);
    }

    #[Test]
    public function it_lists_tasks_with_a_table_prefix_in_completion_and_date_order(): void
    {
        $topic = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'flint',
            'type' => 'topic',
        ]);
        $integration = Integration::factory()->create(['user_id' => $this->user->id, 'service' => 'flint']);
        $tasks = [];
        foreach ([
            ['completed_at' => '2026-10-01T10:00:00Z', 'due_on' => '2026-09-01'],
            [],
            ['due_on' => '2026-10-04', 'review_on' => '2026-10-01'],
            ['review_on' => '2026-10-03'],
            ['due_on' => '2026-10-02'],
            ['due_on' => '2026-10-02'],
        ] as $index => $metadata) {
            $event = Event::factory()->create([
                'integration_id' => $integration->id,
                'target_id' => $topic->id,
                'service' => 'flint',
                'action' => 'had_topic_task',
            ]);
            $tasks[] = Block::factory()->create([
                'event_id' => $event->id,
                'block_type' => 'flint_topic_task',
                'metadata' => $metadata,
                'created_at' => now()->subMinutes(10 - $index),
            ]);
        }

        $connection = (new Block)->getConnection();
        $prefix = $connection->getTablePrefix();
        $tables = ['blocks', 'events', 'integrations'];

        try {
            foreach ($tables as $table) {
                $source = $connection->getQueryGrammar()->wrapTable($table);
                $connection->statement('CREATE TEMPORARY VIEW "task_test_' . $table . '" AS SELECT * FROM ' . $source);
            }
            $connection->setTablePrefix('task_test_');

            $result = app(FlintTopicTaskService::class)->list($this->user, $topic->id);

            $this->assertSame(
                array_map(fn (int $index): string => (string) $tasks[$index]->id, [4, 5, 3, 2, 1, 0]),
                array_column($result, 'id'),
            );
        } finally {
            $connection->setTablePrefix($prefix);
            foreach ($tables as $table) {
                $connection->statement('DROP VIEW IF EXISTS "task_test_' . $table . '"');
            }
        }
    }
}
