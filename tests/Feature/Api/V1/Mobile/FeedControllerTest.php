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

class FeedControllerTest extends TestCase
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
        $this->getJson('/api/v1/mobile/feed')->assertStatus(401);
    }

    #[Test]
    public function returns_paginated_feed_in_reverse_chronological_order(): void
    {
        $this->seedEvents(3);
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $response = $this->getJson('/api/v1/mobile/feed?limit=2')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'time', 'service', 'domain', 'action']],
                'next_cursor',
                'has_more',
            ])
            ->assertJsonPath('has_more', true);

        $this->assertCount(2, $response->json('data'));
        $this->assertNotNull($response->json('next_cursor'));
    }

    #[Test]
    public function paginates_with_cursor(): void
    {
        $this->seedEvents(3);
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $first = $this->getJson('/api/v1/mobile/feed?limit=2')->assertOk();
        $cursor = $first->json('next_cursor');

        $second = $this->getJson('/api/v1/mobile/feed?limit=2&cursor=' . urlencode($cursor))
            ->assertOk()
            ->assertJsonPath('has_more', false);

        $this->assertCount(1, $second->json('data'));
        $this->assertNull($second->json('next_cursor'));
    }

    #[Test]
    public function etag_returns_304_on_match(): void
    {
        $this->seedEvents(1);
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $first = $this->getJson('/api/v1/mobile/feed')->assertOk();
        $etag = $first->headers->get('ETag');

        $this->getJson('/api/v1/mobile/feed', ['If-None-Match' => $etag])
            ->assertStatus(304);
    }

    protected function seedEvents(int $count): void
    {
        $actor = EventObject::factory()->create(['user_id' => $this->user->id]);
        $target = EventObject::factory()->create(['user_id' => $this->user->id]);

        foreach (range(0, $count - 1) as $i) {
            Event::factory()->create([
                'integration_id' => $this->integration->id,
                'service' => 'monzo',
                'domain' => 'money',
                'action' => 'card_payment_to',
                'value' => 10,
                'value_multiplier' => 1,
                'value_unit' => 'GBP',
                'time' => Carbon::now()->subMinutes($i),
                'actor_id' => $actor->id,
                'target_id' => $target->id,
            ]);
        }
    }
}
