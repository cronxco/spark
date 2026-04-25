<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BlocksControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);

        $this->user = User::factory()->create();

        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'monzo',
        ]);

        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => 'monzo',
        ]);
    }

    #[Test]
    public function requires_authentication(): void
    {
        $block = $this->createBlock();
        $this->getJson("/api/v1/mobile/blocks/{$block->id}")->assertStatus(401);
    }

    #[Test]
    public function returns_compact_block_shape(): void
    {
        $block = $this->createBlock();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->getJson("/api/v1/mobile/blocks/{$block->id}")
            ->assertOk()
            ->assertJsonPath('id', $block->id)
            ->assertJsonPath('title', 'Test Block')
            ->assertJsonPath('block_type', 'note');
    }

    #[Test]
    public function denies_access_to_other_users_blocks(): void
    {
        $block = $this->createBlock();
        $otherUser = User::factory()->create();
        Sanctum::actingAs($otherUser, ['ios:read', 'ios:write']);

        $this->getJson("/api/v1/mobile/blocks/{$block->id}")->assertStatus(404);
    }

    #[Test]
    public function returns_404_for_malformed_id(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);
        $this->getJson('/api/v1/mobile/blocks/not-a-uuid')->assertStatus(404);
    }

    #[Test]
    public function etag_returns_304_on_match(): void
    {
        $block = $this->createBlock();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $first = $this->getJson("/api/v1/mobile/blocks/{$block->id}")->assertOk();
        $etag = $first->headers->get('ETag');

        $this->getJson("/api/v1/mobile/blocks/{$block->id}", ['If-None-Match' => $etag])
            ->assertStatus(304);
    }

    protected function createBlock(): Block
    {
        $actor = EventObject::factory()->create(['user_id' => $this->user->id]);
        $target = EventObject::factory()->create(['user_id' => $this->user->id]);

        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'monzo',
            'domain' => 'money',
            'action' => 'card_payment_to',
            'value' => 10,
            'value_multiplier' => 1,
            'value_unit' => 'GBP',
            'time' => Carbon::today(),
            'actor_id' => $actor->id,
            'target_id' => $target->id,
        ]);

        return Block::factory()->create([
            'event_id' => $event->id,
            'title' => 'Test Block',
            'block_type' => 'note',
        ]);
    }
}
