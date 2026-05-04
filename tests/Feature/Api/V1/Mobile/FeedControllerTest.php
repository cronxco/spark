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
    public function knowledge_events_include_target_media_url_and_blocks_count(): void
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
        $this->assertSame(2, $item['blocks_count']);
        $this->assertSame('A brief summary.', $item['tldr']);
        $this->assertArrayNotHasKey('summary_paragraph', $item);
        $this->assertArrayNotHasKey('blocks', $item);
    }

    #[Test]
    public function feed_events_never_embed_blocks_array_only_count(): void
    {
        $this->seedEvents(1, domain: 'money');
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $item = $this->getJson('/api/v1/mobile/feed')
            ->assertOk()
            ->json('data.0');

        $this->assertArrayNotHasKey('blocks', $item);
        $this->assertArrayHasKey('blocks_count', $item);
        $this->assertArrayNotHasKey('summary_paragraph', $item);
    }

    #[Test]
    public function tldr_appears_in_feed_for_any_domain_when_tldr_block_exists(): void
    {
        $actor = EventObject::factory()->create(['user_id' => $this->user->id]);

        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'monzo',
            'domain' => 'money',
            'action' => 'card_payment_to',
            'time' => now(),
            'actor_id' => $actor->id,
        ]);

        Block::factory()->create([
            'event_id' => $event->id,
            'block_type' => 'payment_tldr',
            'metadata' => ['content' => 'You paid Pret £3.50.'],
        ]);

        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $item = $this->getJson('/api/v1/mobile/feed?domain=money')
            ->assertOk()
            ->json('data.0');

        $this->assertSame('You paid Pret £3.50.', $item['tldr']);
        $this->assertArrayNotHasKey('blocks', $item);
    }

    #[Test]
    public function feed_events_include_actor_and_target_type(): void
    {
        $this->seedEvents(1);
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $item = $this->getJson('/api/v1/mobile/feed')->assertOk()->json('data.0');

        $this->assertArrayHasKey('type', $item['actor']);
        $this->assertArrayHasKey('type', $item['target']);
    }

    #[Test]
    public function feed_events_include_media_url_on_actor_and_target(): void
    {
        $actor = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'media_url' => 'https://example.com/actor.jpg',
        ]);
        $target = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'media_url' => 'https://example.com/target.jpg',
        ]);

        Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'monzo',
            'domain' => 'money',
            'action' => 'card_payment_to',
            'value' => 10,
            'value_unit' => 'GBP',
            'time' => now(),
            'actor_id' => $actor->id,
            'target_id' => $target->id,
        ]);

        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $item = $this->getJson('/api/v1/mobile/feed')->assertOk()->json('data.0');

        $this->assertSame('https://example.com/actor.jpg', $item['actor']['media_url']);
        $this->assertSame('https://example.com/target.jpg', $item['target']['media_url']);
    }

    #[Test]
    public function feed_events_include_tags_as_objects(): void
    {
        $this->seedEvents(1);
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $item = $this->getJson('/api/v1/mobile/feed')->assertOk()->json('data.0');

        $this->assertArrayHasKey('tags', $item);
        $this->assertIsArray($item['tags']);
    }

    #[Test]
    public function feed_events_include_blocks_count(): void
    {
        $this->seedEvents(1);
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $item = $this->getJson('/api/v1/mobile/feed')->assertOk()->json('data.0');

        $this->assertArrayHasKey('blocks_count', $item);
        $this->assertIsInt($item['blocks_count']);
        $this->assertSame(0, $item['blocks_count']);
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

    #[Test]
    public function events_include_display_name_and_hidden_flag(): void
    {
        $this->seedEvents(1);
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $item = $this->getJson('/api/v1/mobile/feed')->assertOk()->json('data.0');

        $this->assertSame('Card Payment', $item['display_name']);
        $this->assertFalse($item['hidden']);
    }

    #[Test]
    public function events_with_value_include_display_value(): void
    {
        $this->seedEvents(1);
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $item = $this->getJson('/api/v1/mobile/feed')->assertOk()->json('data.0');

        $this->assertArrayHasKey('display_value', $item);
        $this->assertIsString($item['display_value']);
        $this->assertNotEmpty($item['display_value']);
    }

    #[Test]
    public function hidden_action_is_flagged_in_feed(): void
    {
        $actor = EventObject::factory()->create(['user_id' => $this->user->id]);

        Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'monzo',
            'domain' => 'money',
            'action' => 'had_balance',
            'value' => 10000,
            'value_multiplier' => 100,
            'value_unit' => 'GBP',
            'time' => now(),
            'actor_id' => $actor->id,
        ]);

        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $item = $this->getJson('/api/v1/mobile/feed')->assertOk()->json('data.0');

        $this->assertTrue($item['hidden']);
        $this->assertSame('Balance Update', $item['display_name']);
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
