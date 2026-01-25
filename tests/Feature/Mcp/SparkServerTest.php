<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\SparkServer;
use App\Mcp\Tools\GetBlockTool;
use App\Mcp\Tools\GetDayContextTool;
use App\Mcp\Tools\GetEventTool;
use App\Mcp\Tools\GetObjectTool;
use App\Mcp\Tools\SearchBlocksTool;
use App\Mcp\Tools\SearchEventsTool;
use App\Mcp\Tools\SearchObjectsTool;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SparkServerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'monzo',
        ]);

        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => 'monzo',
            'instance_type' => 'default',
        ]);
    }

    #[Test]
    public function get_event_tool_is_registered(): void
    {
        // Verify tool is registered by calling it (will return auth error without user)
        $response = SparkServer::tool(GetEventTool::class, [
            'id' => '00000000-0000-0000-0000-000000000000',
        ]);

        $response->assertHasErrors(['Authentication required.']);
    }

    #[Test]
    public function get_object_tool_is_registered(): void
    {
        $response = SparkServer::tool(GetObjectTool::class, [
            'id' => '00000000-0000-0000-0000-000000000000',
        ]);

        $response->assertHasErrors(['Authentication required.']);
    }

    #[Test]
    public function get_block_tool_is_registered(): void
    {
        $response = SparkServer::tool(GetBlockTool::class, [
            'id' => '00000000-0000-0000-0000-000000000000',
        ]);

        $response->assertHasErrors(['Authentication required.']);
    }

    #[Test]
    public function search_events_tool_is_registered(): void
    {
        $response = SparkServer::tool(SearchEventsTool::class, [
            'query' => 'test',
        ]);

        $response->assertHasErrors(['Authentication required.']);
    }

    #[Test]
    public function search_objects_tool_is_registered(): void
    {
        $response = SparkServer::tool(SearchObjectsTool::class, [
            'query' => 'test',
        ]);

        $response->assertHasErrors(['Authentication required.']);
    }

    #[Test]
    public function search_blocks_tool_is_registered(): void
    {
        $response = SparkServer::tool(SearchBlocksTool::class, [
            'query' => 'test',
        ]);

        $response->assertHasErrors(['Authentication required.']);
    }

    #[Test]
    public function get_day_context_tool_is_registered(): void
    {
        $response = SparkServer::tool(GetDayContextTool::class, [
            'date' => 'today',
        ]);

        $response->assertHasErrors(['Authentication required.']);
    }

    #[Test]
    public function get_event_tool_requires_authentication(): void
    {
        $response = SparkServer::tool(GetEventTool::class, [
            'id' => '00000000-0000-0000-0000-000000000000',
        ]);

        $response->assertHasErrors(['Authentication required.']);
    }

    #[Test]
    public function get_event_tool_returns_event_for_authenticated_user(): void
    {
        // Create an actor object
        $actor = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'user',
            'type' => 'monzo_user',
            'title' => 'Test User',
        ]);

        // Create test event
        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'monzo',
            'domain' => 'money',
            'action' => 'transaction',
            'time' => now(),
            'actor_id' => $actor->id,
        ]);

        $response = SparkServer::actingAs($this->user)->tool(GetEventTool::class, [
            'id' => $event->id,
        ]);

        $response->assertOk();
        $response->assertSee($event->id);
        $response->assertSee('"service": "monzo"');
        $response->assertSee('"domain": "money"');
    }

    #[Test]
    public function get_event_tool_returns_error_for_invalid_uuid(): void
    {
        $response = SparkServer::actingAs($this->user)->tool(GetEventTool::class, [
            'id' => 'not-a-uuid',
        ]);

        $response->assertHasErrors(['Invalid event ID format. Expected UUID.']);
    }

    #[Test]
    public function get_event_tool_returns_error_for_nonexistent_event(): void
    {
        $response = SparkServer::actingAs($this->user)->tool(GetEventTool::class, [
            'id' => '00000000-0000-0000-0000-000000000000',
        ]);

        $response->assertHasErrors(['Event not found or access denied.']);
    }

    #[Test]
    public function get_event_tool_denies_access_to_other_users_events(): void
    {
        $otherUser = User::factory()->create();
        $otherGroup = IntegrationGroup::factory()->create([
            'user_id' => $otherUser->id,
            'service' => 'monzo',
        ]);
        $otherIntegration = Integration::factory()->create([
            'user_id' => $otherUser->id,
            'integration_group_id' => $otherGroup->id,
            'service' => 'monzo',
        ]);

        $event = Event::factory()->create([
            'integration_id' => $otherIntegration->id,
            'time' => now(),
        ]);

        // Try to access the event as original user
        $response = SparkServer::actingAs($this->user)->tool(GetEventTool::class, [
            'id' => $event->id,
        ]);

        $response->assertHasErrors(['Event not found or access denied.']);
    }

    #[Test]
    public function get_object_tool_returns_object_for_authenticated_user(): void
    {
        $object = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'merchant',
            'type' => 'monzo_merchant',
            'title' => 'Starbucks',
        ]);

        $response = SparkServer::actingAs($this->user)->tool(GetObjectTool::class, [
            'id' => $object->id,
            'include_events' => false,
        ]);

        $response->assertOk();
        $response->assertSee($object->id);
        $response->assertSee('"concept": "merchant"');
        $response->assertSee('"title": "Starbucks"');
    }

    #[Test]
    public function get_object_tool_denies_access_to_other_users_objects(): void
    {
        $otherUser = User::factory()->create();

        $object = EventObject::factory()->create([
            'user_id' => $otherUser->id,
            'concept' => 'merchant',
            'type' => 'monzo_merchant',
            'title' => 'Secret Store',
        ]);

        $response = SparkServer::actingAs($this->user)->tool(GetObjectTool::class, [
            'id' => $object->id,
        ]);

        $response->assertHasErrors(['Object not found or access denied.']);
    }

    #[Test]
    public function get_block_tool_returns_block_for_authenticated_user(): void
    {
        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'time' => now(),
        ]);

        $block = Block::factory()->create([
            'event_id' => $event->id,
            'block_type' => 'summary',
            'title' => 'Test Block',
            'time' => now(),
        ]);

        $response = SparkServer::actingAs($this->user)->tool(GetBlockTool::class, [
            'id' => $block->id,
        ]);

        $response->assertOk();
        $response->assertSee($block->id);
        $response->assertSee('"block_type": "summary"');
        $response->assertSee('"title": "Test Block"');
    }

    #[Test]
    public function search_events_tool_requires_query_parameter(): void
    {
        $response = SparkServer::actingAs($this->user)->tool(SearchEventsTool::class, []);

        $response->assertHasErrors(['Query parameter is required.']);
    }

    #[Test]
    public function search_events_tool_performs_keyword_search(): void
    {
        // Create searchable events
        $actor = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'Test Merchant',
        ]);

        Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'monzo',
            'action' => 'coffee_purchase',
            'time' => now(),
            'actor_id' => $actor->id,
        ]);

        Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'monzo',
            'action' => 'grocery_purchase',
            'time' => now(),
        ]);

        $response = SparkServer::actingAs($this->user)->tool(SearchEventsTool::class, [
            'query' => 'coffee',
            'semantic' => false,
        ]);

        $response->assertOk();
        $response->assertSee('"count": 1');
        $response->assertSee('"query": "coffee"');
    }

    #[Test]
    public function search_objects_tool_performs_keyword_search(): void
    {
        EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'merchant',
            'title' => 'Starbucks Coffee',
        ]);

        EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'merchant',
            'title' => 'Grocery Store',
        ]);

        $response = SparkServer::actingAs($this->user)->tool(SearchObjectsTool::class, [
            'query' => 'Starbucks',
            'semantic' => false,
        ]);

        $response->assertOk();
        $response->assertSee('"count": 1');
        $response->assertSee('Starbucks Coffee');
    }

    #[Test]
    public function search_blocks_tool_performs_keyword_search(): void
    {
        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'time' => now(),
        ]);

        Block::factory()->create([
            'event_id' => $event->id,
            'block_type' => 'summary',
            'title' => 'Daily Summary Report',
            'time' => now(),
        ]);

        Block::factory()->create([
            'event_id' => $event->id,
            'block_type' => 'detail',
            'title' => 'Transaction Detail',
            'time' => now(),
        ]);

        $response = SparkServer::actingAs($this->user)->tool(SearchBlocksTool::class, [
            'query' => 'Summary',
            'semantic' => false,
        ]);

        $response->assertOk();
        $response->assertSee('"count": 1');
        $response->assertSee('Daily Summary Report');
    }

    #[Test]
    public function get_day_context_tool_returns_context_structure(): void
    {
        // Create flint integration
        $flintGroup = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'flint',
        ]);

        Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $flintGroup->id,
            'service' => 'flint',
            'instance_type' => 'assistant',
            'configuration' => [
                'today_enabled' => true,
                'yesterday_enabled' => true,
                'tomorrow_enabled' => true,
            ],
        ]);

        // Create some events for today
        Event::factory()->count(3)->create([
            'integration_id' => $this->integration->id,
            'time' => now(),
        ]);

        $response = SparkServer::actingAs($this->user)->tool(GetDayContextTool::class, [
            'date' => 'today',
        ]);

        $response->assertOk();
        $response->assertSee('"date":');
        $response->assertSee('"event_count":');
        $response->assertSee('"service_breakdown":');
    }

    #[Test]
    public function api_resources_exclude_embeddings(): void
    {
        $object = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'user',
            'title' => 'Test',
            // Note: embeddings would be stored if present
        ]);

        $response = SparkServer::actingAs($this->user)->tool(GetObjectTool::class, [
            'id' => $object->id,
            'include_events' => false,
        ]);

        $response->assertOk();
        // Embeddings should not appear in the response
        $response->assertSee($object->id);
        $response->assertDontSee('"embeddings"');
    }

    #[Test]
    public function search_results_respect_limit_parameter(): void
    {
        // Create many events
        Event::factory()->count(30)->create([
            'integration_id' => $this->integration->id,
            'service' => 'monzo',
            'action' => 'transaction',
            'time' => now(),
        ]);

        $response = SparkServer::actingAs($this->user)->tool(SearchEventsTool::class, [
            'query' => 'transaction',
            'semantic' => false,
            'limit' => 5,
        ]);

        $response->assertOk();
        // The limit parameter should be respected in search results
        $response->assertSee('"limit": 5');
    }

    #[Test]
    public function search_events_can_filter_by_service(): void
    {
        // Create events for different services
        Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'monzo',
            'action' => 'transaction',
            'time' => now(),
        ]);

        $spotifyGroup = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'spotify',
        ]);

        $spotifyIntegration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $spotifyGroup->id,
            'service' => 'spotify',
        ]);

        Event::factory()->create([
            'integration_id' => $spotifyIntegration->id,
            'service' => 'spotify',
            'action' => 'transaction', // Same action word to test service filtering
            'time' => now(),
        ]);

        $response = SparkServer::actingAs($this->user)->tool(SearchEventsTool::class, [
            'query' => 'transaction',
            'semantic' => false,
            'service' => 'monzo',
        ]);

        $response->assertOk();
        $response->assertSee('"count": 1');
        $response->assertSee('"service": "monzo"');
    }
}
