<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConditionalWritesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_owned_entity_write_requires_a_current_strong_etag(): void
    {
        [$user, $event] = $this->eventForUser();
        Sanctum::actingAs($user, ['ios:read', 'ios:write']);

        $this->patchJson("/api/v1/mobile/events/{$event->id}", ['action' => 'changed'])
            ->assertStatus(428)->assertHeader('ETag');

        $read = $this->getJson("/api/v1/mobile/events/{$event->id}")->assertOk()->assertHeader('ETag');
        $etag = $read->headers->get('ETag');
        $this->assertStringNotContainsString('W/', $etag);

        $this->patchJson("/api/v1/mobile/events/{$event->id}", ['action' => 'changed'], ['If-Match' => $etag])
            ->assertOk()->assertJsonPath('action', 'changed')->assertHeader('ETag');

        $this->patchJson("/api/v1/mobile/events/{$event->id}", ['action' => 'again'], ['If-Match' => $etag])
            ->assertStatus(412)->assertHeader('ETag');
    }

    #[Test]
    public function conditional_writes_do_not_disclose_another_users_entity(): void
    {
        [, $event] = $this->eventForUser();
        Sanctum::actingAs(User::factory()->create(), ['ios:read', 'ios:write']);

        $this->patchJson("/api/v1/mobile/events/{$event->id}", ['action' => 'changed'], ['If-Match' => '"anything"'])
            ->assertNotFound();
    }

    /** @return array{User, Event} */
    private function eventForUser(): array
    {
        config(['ios.mobile_api_enabled' => true]);
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id, 'service' => 'monzo']);
        $integration = Integration::factory()->create(['user_id' => $user->id, 'integration_group_id' => $group->id, 'service' => 'monzo']);
        $actor = EventObject::factory()->create(['user_id' => $user->id]);
        $event = Event::factory()->create(['integration_id' => $integration->id, 'actor_id' => $actor->id, 'service' => 'monzo']);

        return [$user, $event];
    }
}
