<?php

namespace Tests\Unit\Models;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IntegrationGroupTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    #[Test]
    public function it_generates_uuid_on_creation(): void
    {
        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'test',
        ]);

        $this->assertNotNull($group->id);
        $this->assertTrue(Str::isUuid($group->id));
    }

    #[Test]
    public function it_belongs_to_a_user(): void
    {
        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'test',
        ]);

        $this->assertInstanceOf(User::class, $group->user);
        $this->assertEquals($this->user->id, $group->user->id);
    }

    #[Test]
    public function it_has_many_integrations(): void
    {
        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'test',
        ]);

        Integration::factory()->count(3)->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => 'test',
        ]);

        $this->assertCount(3, $group->integrations);
        $this->assertInstanceOf(Integration::class, $group->integrations->first());
    }

    #[Test]
    public function it_uses_soft_deletes(): void
    {
        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'test',
        ]);

        $group->delete();

        $this->assertSoftDeleted('integration_groups', ['id' => $group->id]);
        $this->assertNotNull(IntegrationGroup::withTrashed()->find($group->id));
    }

    #[Test]
    public function it_casts_expiry_to_datetime(): void
    {
        $expiry = now()->addHours(1);

        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'test',
            'expiry' => $expiry,
        ]);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $group->expiry);
    }

    #[Test]
    public function it_casts_refresh_expiry_to_datetime(): void
    {
        $refreshExpiry = now()->addDays(30);

        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'test',
            'refresh_expiry' => $refreshExpiry,
        ]);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $group->refresh_expiry);
    }

    #[Test]
    public function it_casts_auth_metadata_to_array(): void
    {
        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'test',
            'auth_metadata' => ['key' => 'value', 'nested' => ['a' => 1]],
        ]);

        $this->assertIsArray($group->auth_metadata);
        $this->assertEquals('value', $group->auth_metadata['key']);
        $this->assertEquals(1, $group->auth_metadata['nested']['a']);
    }

    #[Test]
    public function it_stores_oauth_tokens(): void
    {
        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'test',
            'access_token' => 'test_access_token',
            'refresh_token' => 'test_refresh_token',
        ]);

        $this->assertEquals('test_access_token', $group->access_token);
        $this->assertEquals('test_refresh_token', $group->refresh_token);
    }

    #[Test]
    public function it_stores_webhook_secret(): void
    {
        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'test',
            'webhook_secret' => 'secret_webhook_key',
        ]);

        $this->assertEquals('secret_webhook_key', $group->webhook_secret);
    }

    #[Test]
    public function get_related_events_returns_events_from_integrations(): void
    {
        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'test',
        ]);

        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => 'test',
        ]);

        Event::factory()->count(5)->create([
            'integration_id' => $integration->id,
        ]);

        $events = $group->getRelatedEvents();

        $this->assertCount(5, $events);
    }

    #[Test]
    public function get_related_blocks_returns_blocks_from_events(): void
    {
        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'test',
        ]);

        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => 'test',
        ]);

        $event = Event::factory()->create([
            'integration_id' => $integration->id,
        ]);

        Block::factory()->count(3)->create([
            'event_id' => $event->id,
        ]);

        $blocks = $group->getRelatedBlocks();

        $this->assertCount(3, $blocks);
    }

    #[Test]
    public function get_related_objects_returns_actors_and_targets(): void
    {
        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'test',
        ]);

        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => 'test',
        ]);

        $actor = EventObject::factory()->create(['user_id' => $this->user->id]);
        $target = EventObject::factory()->create(['user_id' => $this->user->id]);

        Event::factory()->create([
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'target_id' => $target->id,
        ]);

        $objects = $group->getRelatedObjects();

        $this->assertCount(2, $objects);
        $this->assertContains($actor->id, $objects->pluck('id'));
        $this->assertContains($target->id, $objects->pluck('id'));
    }

    #[Test]
    public function get_deletion_summary_returns_correct_counts(): void
    {
        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'test_service',
            'account_id' => 'test_account_123',
        ]);

        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => 'test',
        ]);

        $actor = EventObject::factory()->create(['user_id' => $this->user->id]);
        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
        ]);

        Block::factory()->count(2)->create(['event_id' => $event->id]);

        $summary = $group->getDeletionSummary();

        $this->assertEquals(1, $summary['integrations']);
        $this->assertEquals(1, $summary['events']);
        $this->assertEquals(2, $summary['blocks']);
        $this->assertEquals(1, $summary['objects']);
        $this->assertEquals('test_service', $summary['service_name']);
        $this->assertEquals('test_account_123', $summary['account_id']);
    }

    #[Test]
    public function get_uuid_block_returns_first_segment(): void
    {
        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'test',
        ]);

        $uuidBlock = $group->getUuidBlock();

        $this->assertEquals(explode('-', $group->id)[0], $uuidBlock);
    }

    #[Test]
    public function it_stores_account_id(): void
    {
        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'test',
            'account_id' => 'external_account_id_123',
        ]);

        $this->assertEquals('external_account_id_123', $group->account_id);
    }
}
