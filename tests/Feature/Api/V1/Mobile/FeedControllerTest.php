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

    #[Test]
    public function filters_feed_by_domain(): void
    {
        $this->seedEvents(2, domain: 'money');
        $this->seedEvents(1, domain: 'health');
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $response = $this->getJson('/api/v1/mobile/feed?domain=money')
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame('money', $response->json('data.0.domain'));
    }

    #[Test]
    public function rejects_invalid_domain(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->getJson('/api/v1/mobile/feed?domain=bogus')
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'hint']);
    }

    #[Test]
    public function knowledge_events_include_enrichment_fields(): void
    {
        $actor = EventObject::factory()->create(['user_id' => $this->user->id]);
        $target = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'media_url' => 'https://example.com/image.jpg',
        ]);

        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'fetch',
            'domain' => 'knowledge',
            'action' => 'read_article',
            'time' => now(),
            'actor_id' => $actor->id,
            'target_id' => $target->id,
        ]);

        Block::factory()->create([
            'event_id' => $event->id,
            'block_type' => 'fetch_tldr',
            'metadata' => ['content' => 'A brief summary.'],
        ]);

        Block::factory()->create([
            'event_id' => $event->id,
            'block_type' => 'fetch_summary_paragraph',
            'metadata' => ['content' => 'A longer paragraph summary.'],
        ]);

        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $response = $this->getJson('/api/v1/mobile/feed?domain=knowledge')
            ->assertOk();

        $item = $response->json('data.0');
        $this->assertSame('https://example.com/image.jpg', $item['target']['media_url']);
        $this->assertSame('A brief summary.', $item['tldr']);
        $this->assertSame('A longer paragraph summary.', $item['summary_paragraph']);
    }

    #[Test]
    public function non_knowledge_events_omit_enrichment_fields(): void
    {
        $this->seedEvents(1, domain: 'money');
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $item = $this->getJson('/api/v1/mobile/feed?domain=money')
            ->assertOk()
            ->json('data.0');

        $this->assertArrayNotHasKey('tldr', $item);
        $this->assertArrayNotHasKey('media_url', $item['target'] ?? []);
    }

    #[Test]
    public function default_feed_excludes_future_events(): void
    {
        $this->seedEvents(2);
        $this->seedEventsAtTime(1, Carbon::now()->addDay());
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $response = $this->getJson('/api/v1/mobile/feed')->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    #[Test]
    public function date_parameter_returns_only_events_from_that_date(): void
    {
        $targetDate = Carbon::now()->subDays(3)->startOfDay();
        $this->seedEventsAtTime(2, $targetDate->copy()->addHours(10));
        $this->seedEvents(2);
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $response = $this->getJson('/api/v1/mobile/feed?date=' . $targetDate->format('Y-m-d'))->assertOk();

        $this->assertCount(2, $response->json('data'));
        foreach ($response->json('data') as $event) {
            $this->assertSame($targetDate->format('Y-m-d'), Carbon::parse($event['time'])->format('Y-m-d'));
        }
    }

    #[Test]
    public function date_parameter_can_target_a_future_date(): void
    {
        $futureDate = Carbon::now()->addDays(2)->startOfDay();
        $this->seedEventsAtTime(1, $futureDate->copy()->addHours(9));
        $this->seedEvents(2);
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $response = $this->getJson('/api/v1/mobile/feed?date=' . $futureDate->format('Y-m-d'))->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($futureDate->format('Y-m-d'), Carbon::parse($response->json('data.0.time'))->format('Y-m-d'));
    }

    #[Test]
    public function rejects_invalid_date_format(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->getJson('/api/v1/mobile/feed?date=not-a-date')
            ->assertStatus(422)
            ->assertJsonStructure(['message']);
    }

    protected function seedEvents(int $count, string $domain = 'money'): void
    {
        $actor = EventObject::factory()->create(['user_id' => $this->user->id]);
        $target = EventObject::factory()->create(['user_id' => $this->user->id]);

        foreach (range(0, $count - 1) as $i) {
            Event::factory()->create([
                'integration_id' => $this->integration->id,
                'service' => 'monzo',
                'domain' => $domain,
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

    protected function seedEventsAtTime(int $count, Carbon $time, string $domain = 'money'): void
    {
        $actor = EventObject::factory()->create(['user_id' => $this->user->id]);
        $target = EventObject::factory()->create(['user_id' => $this->user->id]);

        foreach (range(0, $count - 1) as $i) {
            Event::factory()->create([
                'integration_id' => $this->integration->id,
                'service' => 'monzo',
                'domain' => $domain,
                'action' => 'card_payment_to',
                'value' => 10,
                'value_multiplier' => 1,
                'value_unit' => 'GBP',
                'time' => $time->copy()->addMinutes($i),
                'actor_id' => $actor->id,
                'target_id' => $target->id,
            ]);
        }
    }
}
