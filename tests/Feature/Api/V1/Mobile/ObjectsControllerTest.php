<?php

namespace Tests\Feature\Api\V1\Mobile;

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

class ObjectsControllerTest extends TestCase
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
        $object = EventObject::factory()->create(['user_id' => $this->user->id]);
        $this->getJson("/api/v1/mobile/objects/{$object->id}")->assertStatus(401);
    }

    #[Test]
    public function returns_compact_object_with_recent_events(): void
    {
        $object = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'merchant',
            'type' => 'shop',
            'title' => 'Test Shop',
        ]);

        Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'monzo',
            'domain' => 'money',
            'action' => 'card_payment_to',
            'value' => 10,
            'value_multiplier' => 1,
            'value_unit' => 'GBP',
            'time' => Carbon::today(),
            'actor_id' => $object->id,
            'target_id' => $object->id,
        ]);

        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->getJson("/api/v1/mobile/objects/{$object->id}")
            ->assertOk()
            ->assertJsonPath('id', $object->id)
            ->assertJsonPath('title', 'Test Shop')
            ->assertJsonStructure(['id', 'concept', 'type', 'title', 'recent_events']);
    }

    #[Test]
    public function can_exclude_recent_events(): void
    {
        $object = EventObject::factory()->create(['user_id' => $this->user->id]);
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->getJson("/api/v1/mobile/objects/{$object->id}?include_events=0")
            ->assertOk()
            ->assertJsonMissingPath('recent_events');
    }

    #[Test]
    public function denies_access_to_other_users_objects(): void
    {
        $object = EventObject::factory()->create(['user_id' => $this->user->id]);
        $otherUser = User::factory()->create();
        Sanctum::actingAs($otherUser, ['ios:read', 'ios:write']);

        $this->getJson("/api/v1/mobile/objects/{$object->id}")->assertStatus(404);
    }

    #[Test]
    public function returns_404_for_malformed_id(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);
        $this->getJson('/api/v1/mobile/objects/not-a-uuid')->assertStatus(404);
    }

    #[Test]
    public function etag_returns_304_on_match(): void
    {
        $object = EventObject::factory()->create(['user_id' => $this->user->id]);
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $first = $this->getJson("/api/v1/mobile/objects/{$object->id}")->assertOk();
        $etag = $first->headers->get('ETag');

        $this->getJson("/api/v1/mobile/objects/{$object->id}", ['If-None-Match' => $etag])
            ->assertStatus(304);
    }
}
