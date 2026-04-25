<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\MetricStatistic;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SearchControllerTest extends TestCase
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
            'name' => 'Personal Monzo',
        ]);
    }

    #[Test]
    public function requires_authentication(): void
    {
        $this->getJson('/api/v1/mobile/search?q=test')->assertStatus(401);
    }

    #[Test]
    public function rejects_invalid_mode(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->getJson('/api/v1/mobile/search?q=hello&mode=bogus')
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'modes']);
    }

    #[Test]
    public function default_mode_matches_events_and_objects(): void
    {
        $actor = EventObject::factory()->create(['user_id' => $this->user->id]);
        $target = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'Tesco Metro',
        ]);

        Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'monzo',
            'domain' => 'money',
            'action' => 'card_payment_to',
            'value' => 1000,
            'value_multiplier' => 100,
            'value_unit' => 'GBP',
            'time' => Carbon::today(),
            'actor_id' => $actor->id,
            'target_id' => $target->id,
        ]);

        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $response = $this->getJson('/api/v1/mobile/search?q=Tesco&mode=default')->assertOk();

        $response->assertJsonPath('mode', 'default');
        $this->assertNotEmpty($response->json('objects'));
    }

    #[Test]
    public function integration_mode_returns_matching_integrations(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->getJson('/api/v1/mobile/search?q=monzo&mode=integration')
            ->assertOk()
            ->assertJsonPath('mode', 'integration')
            ->assertJsonCount(1, 'integrations')
            ->assertJsonPath('integrations.0.service', 'monzo');
    }

    #[Test]
    public function metric_mode_returns_matching_metrics(): void
    {
        MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'action' => 'had_sleep_score',
            'value_unit' => 'percent',
        ]);

        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->getJson('/api/v1/mobile/search?q=sleep&mode=metric')
            ->assertOk()
            ->assertJsonPath('mode', 'metric')
            ->assertJsonCount(1, 'metrics')
            ->assertJsonPath('metrics.0.action', 'had_sleep_score');
    }

    #[Test]
    public function tag_mode_returns_matching_events(): void
    {
        $actor = EventObject::factory()->create(['user_id' => $this->user->id]);
        $target = EventObject::factory()->create(['user_id' => $this->user->id]);

        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'monzo',
            'domain' => 'money',
            'action' => 'card_payment_to',
            'value' => 500,
            'value_multiplier' => 100,
            'value_unit' => 'GBP',
            'time' => Carbon::today(),
            'actor_id' => $actor->id,
            'target_id' => $target->id,
        ]);

        $event->attachTag('groceries');

        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->getJson('/api/v1/mobile/search?q=groceries&mode=tag')
            ->assertOk()
            ->assertJsonPath('mode', 'tag')
            ->assertJsonCount(1, 'events');
    }
}
