<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use App\Support\SparkAbility;
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
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);

        $this->getJson("/api/v1/mobile/blocks/{$block->id}")
            ->assertOk()
            ->assertJsonPath('id', $block->id)
            ->assertJsonPath('title', 'Test Block')
            ->assertJsonPath('block_type', 'note');
    }

    #[Test]
    public function includes_parent_event_without_its_blocks(): void
    {
        $block = $this->createBlock();
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);

        $this->getJson("/api/v1/mobile/blocks/{$block->id}")
            ->assertOk()
            ->assertJsonPath('event.id', $block->event_id)
            ->assertJsonPath('event.action', 'card_payment_to')
            ->assertJsonPath('event.target.id', $block->event->target_id)
            ->assertJsonMissingPath('event.blocks');
    }

    #[Test]
    public function returns_full_block_content_without_truncation(): void
    {
        $content = str_repeat('Long mobile block content. ', 30);
        $block = $this->createBlock([
            'metadata' => ['content' => $content],
        ]);
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);

        $this->getJson("/api/v1/mobile/blocks/{$block->id}")
            ->assertOk()
            ->assertJsonPath('content', $content);
    }

    #[Test]
    public function denies_access_to_other_users_blocks(): void
    {
        $block = $this->createBlock();
        $otherUser = User::factory()->create();
        Sanctum::actingAs($otherUser, SparkAbility::MOBILE_SESSION);

        $this->getJson("/api/v1/mobile/blocks/{$block->id}")->assertStatus(404);
    }

    #[Test]
    public function returns_404_for_malformed_id(): void
    {
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);
        $this->getJson('/api/v1/mobile/blocks/not-a-uuid')->assertStatus(404);
    }

    #[Test]
    public function etag_returns_304_on_match(): void
    {
        $block = $this->createBlock();
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);

        $first = $this->getJson("/api/v1/mobile/blocks/{$block->id}")->assertOk();
        $etag = $first->headers->get('ETag');

        $this->getJson("/api/v1/mobile/blocks/{$block->id}", ['If-None-Match' => $etag])
            ->assertStatus(304);
    }

    #[Test]
    public function presents_the_block_with_its_plugin_name_glyph_and_domain(): void
    {
        $block = $this->createBlock(['block_type' => 'merchant', 'title' => 'Pret']);
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);

        $this->getJson("/api/v1/mobile/blocks/{$block->id}")
            ->assertOk()
            ->assertJsonPath('display_name', 'Merchant')
            ->assertJsonPath('icon', 'fas.store')
            ->assertJsonPath('domain', 'money')
            ->assertJsonPath('ai_written', false)
            ->assertJsonPath('hidden', false);
    }

    #[Test]
    public function flags_generated_text(): void
    {
        $block = $this->createBlock(['block_type' => 'fetch_tldr', 'metadata' => ['content' => 'Short']]);
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);

        $this->getJson("/api/v1/mobile/blocks/{$block->id}")
            ->assertOk()
            ->assertJsonPath('ai_written', true)
            ->assertJsonPath('icon', 'fas.wand-magic-sparkles');
    }

    #[Test]
    public function sends_allowlisted_details_and_never_raw_metadata(): void
    {
        $block = $this->createBlock([
            'block_type' => 'receipt_payment_method',
            'value_unit' => 'GBP',
            'metadata' => [
                'payment_method' => 'contactless',
                'card_last_4' => '**** 4242',
                'terminal_id' => 'T-998877',
                'receipt_number' => 'R-1',
                'model' => 'gpt-test',
                'line_number' => 4,
            ],
        ]);
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);

        $response = $this->getJson("/api/v1/mobile/blocks/{$block->id}")->assertOk();

        $this->assertSame(['payment_method' => 'contactless', 'card_last_4' => '4242'], $response->json('details'));
        $response->assertJsonMissingPath('metadata');
        $this->assertStringNotContainsString('T-998877', $response->getContent());
        $this->assertStringNotContainsString('gpt-test', $response->getContent());
    }

    #[Test]
    public function sends_receipt_money_in_major_units_with_its_currency(): void
    {
        $block = $this->createBlock([
            'block_type' => 'receipt_tax_summary',
            'value' => 200,
            'value_multiplier' => 100,
            'value_unit' => 'eur',
            'metadata' => ['subtotal' => 1250, 'tax_rate' => 20, 'discount_total' => 0, 'tip_amount' => 'n/a'],
        ]);
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);

        $this->getJson("/api/v1/mobile/blocks/{$block->id}")
            ->assertOk()
            ->assertJsonPath('details.subtotal', 12.5)
            ->assertJsonPath('details.tax_rate', 20)
            ->assertJsonPath('details.discount_total', 0)
            ->assertJsonPath('details.currency', 'EUR')
            ->assertJsonMissingPath('details.tip_amount');
    }

    #[Test]
    public function drops_detail_values_of_the_wrong_type(): void
    {
        $block = $this->createBlock([
            'block_type' => 'track_details',
            'metadata' => ['track' => 'Teardrop', 'artists' => ['Massive Attack', ''], 'duration' => 330000, 'popularity' => 'very'],
        ]);
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);

        $this->getJson("/api/v1/mobile/blocks/{$block->id}")
            ->assertOk()
            ->assertJsonPath('details.track', 'Teardrop')
            ->assertJsonPath('details.artists', ['Massive Attack'])
            ->assertJsonPath('details.duration_ms', 330000)
            ->assertJsonMissingPath('details.popularity');
    }

    #[Test]
    public function book_details_read_the_book_object_too(): void
    {
        $block = $this->createBlock([
            'block_type' => 'book',
            'metadata' => ['author' => 'Old Author', 'current_progress' => 40],
        ]);
        $block->event->target->update(['metadata' => ['author' => 'Ursula K. Le Guin', 'num_pages' => '248', 'date_read' => '2026-10-30']]);
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);

        $response = $this->getJson("/api/v1/mobile/blocks/{$block->id}")->assertOk();

        $response->assertJsonPath('details.author', 'Ursula K. Le Guin')
            ->assertJsonPath('details.num_pages', 248)
            ->assertJsonPath('details.current_progress', 40);
        $this->assertStringStartsWith('2026-10-30T', $response->json('details.date_read'));
    }

    #[Test]
    public function omits_details_for_types_without_a_card(): void
    {
        $block = $this->createBlock(['metadata' => ['secret' => 'x']]);
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);

        $this->getJson("/api/v1/mobile/blocks/{$block->id}")
            ->assertOk()
            ->assertJsonMissingPath('details');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function createBlock(array $attributes = []): Block
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

        return Block::factory()->create(array_merge([
            'event_id' => $event->id,
            'title' => 'Test Block',
            'block_type' => 'note',
        ], $attributes));
    }
}
