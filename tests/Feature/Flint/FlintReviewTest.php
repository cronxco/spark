<?php

namespace Tests\Feature\Flint;

use App\Jobs\TaskPipeline\ProcessTaskPipelineJob;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\Relationship;
use App\Models\User;
use App\Services\Flint\FlintReviewService;
use App\Services\Receipt\ReceiptMatchState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MobileSessionAbilities;
use Tests\TestCase;

/** Decisions E-1 and EX-D4: Flint's Review tab, on web and the mobile API. */
class FlintReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Integration $bank;

    private Integration $receipts;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true, 'app.enable_task_pipeline' => false]);
        $this->user = User::factory()->create();
        $this->bank = Integration::factory()->create(['user_id' => $this->user->id, 'service' => 'monzo']);
        $this->receipts = Integration::factory()->create(['user_id' => $this->user->id, 'service' => 'receipt']);
    }

    #[Test]
    public function a_receipt_suggestion_can_be_confirmed_against_one_of_its_candidates(): void
    {
        $transaction = $this->transaction('Coffee House');
        $receipt = $this->receipt('Coffee House', ['needs_review' => true, 'candidate_matches' => [
            ['transaction_id' => $transaction->id, 'confidence' => 0.7, 'source' => 'monzo'],
        ]]);

        $item = collect(app(FlintReviewService::class)->items($this->user))->firstWhere('kind', 'receipt_suggestion');
        $this->assertSame((string) $receipt->id, $item['id']);
        $this->assertSame((string) $transaction->id, $item['candidates'][0]['id']);
        $this->assertSame('Coffee House receipt', $item['subject']['title']);
        $this->assertSame(4.5, $item['subject']['amount']);
        $this->assertSame('GBP', $item['subject']['unit']);
        $this->assertNotNull($item['subject']['time']);
        $this->assertSame('Coffee House', $item['candidates'][0]['title']);

        app(FlintReviewService::class)->act($this->user, 'receipt_suggestion', $receipt->id, 'confirm', ['transaction_id' => $transaction->id]);

        $this->assertTrue(Relationship::where('from_id', $receipt->id)->where('to_id', $transaction->id)->where('type', 'receipt_for')->exists());
        $this->assertSame('matched', ReceiptMatchState::status($receipt->fresh()));
        $this->assertSame([], app(FlintReviewService::class)->items($this->user));
    }

    #[Test]
    public function a_receipt_suggestion_cannot_be_confirmed_against_a_transaction_it_did_not_suggest(): void
    {
        $suggested = $this->transaction('Coffee House');
        $other = $this->transaction('Bakery');
        $receipt = $this->receipt('Coffee House', ['needs_review' => true, 'candidate_matches' => [
            ['transaction_id' => $suggested->id, 'confidence' => 0.7],
        ]]);

        Sanctum::actingAs($this->user, MobileSessionAbilities::with(['ios:read', 'flint:write']));
        $this->postJson("/api/v1/mobile/flint/review/receipt_suggestion/{$receipt->id}", ['action' => 'confirm', 'transaction_id' => $other->id])
            ->assertStatus(422);

        $this->assertFalse(Relationship::where('from_id', $receipt->id)->exists());
    }

    #[Test]
    public function an_automatic_receipt_link_can_be_kept_or_undone(): void
    {
        $keptLink = $this->receiptLink($this->receipt('Coffee House'), $this->transaction('Coffee House'));
        $undoneReceipt = $this->receipt('Bakery', ['is_matched' => true]);
        $undoneLink = $this->receiptLink($undoneReceipt, $this->transaction('Bakery'));

        $kinds = collect(app(FlintReviewService::class)->items($this->user))->pluck('kind');
        $this->assertSame(['receipt_auto_match', 'receipt_auto_match'], $kinds->all());

        Sanctum::actingAs($this->user, MobileSessionAbilities::with(['ios:read', 'ios:write']));
        $this->postJson("/api/v1/mobile/flint/review/receipt_auto_match/{$keptLink->id}", ['action' => 'keep'])->assertOk();
        $this->postJson("/api/v1/mobile/flint/review/receipt_auto_match/{$undoneLink->id}", ['action' => 'undo'])
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertNotNull($keptLink->fresh()->metadata['reviewed_at']);
        $this->assertSoftDeleted($undoneLink);
        $this->assertFalse(ReceiptMatchState::isMatched($undoneReceipt));
    }

    #[Test]
    public function transaction_link_suggestions_and_automatic_links_are_listed_on_one_scale(): void
    {
        $pending = $this->link(['auto_linked' => true, 'pending' => true, 'confidence' => 62.0]);
        $auto = $this->link(['auto_linked' => true, 'confidence' => 91.0]);

        Sanctum::actingAs($this->user, MobileSessionAbilities::with(['ios:read']));
        $items = collect($this->getJson('/api/v1/mobile/flint/review')->assertOk()->json('data'))->keyBy('kind');

        $this->assertSame((string) $pending->id, $items['link_suggestion']['id']);
        $this->assertSame(0.62, $items['link_suggestion']['confidence']);
        $this->assertSame(['confirm', 'dismiss'], $items['link_suggestion']['actions']);
        $this->assertSame((string) $auto->id, $items['auto_link']['id']);
        $this->assertSame(['undo'], $items['auto_link']['actions']);

        app(FlintReviewService::class)->act($this->user, 'link_suggestion', $pending->id, 'confirm');
        $this->assertFalse($pending->fresh()->isPending());
        $this->assertNotNull($pending->fresh()->metadata['reviewed_at']);
        $this->assertNotContains((string) $pending->id, collect(app(FlintReviewService::class)->items($this->user))->pluck('id'));
    }

    #[Test]
    public function older_suggestions_appear_ahead_of_newer_automatic_links(): void
    {
        $transaction = $this->transaction('Coffee House');
        $receipt = $this->receipt('Coffee House', ['needs_review' => true, 'candidate_matches' => [
            ['transaction_id' => $transaction->id, 'confidence' => 0.7],
        ]]);
        $receipt->update(['time' => now()->subMonths(5)]);
        $auto = $this->link(['auto_linked' => true, 'confidence' => 91.0]);

        $items = app(FlintReviewService::class)->items($this->user);

        $this->assertSame(['receipt_suggestion', 'auto_link'], array_column($items, 'kind'));
        $this->assertSame((string) $auto->id, $items[1]['id']);
    }

    #[Test]
    public function another_users_items_are_neither_listed_nor_actionable(): void
    {
        $link = $this->link(['auto_linked' => true, 'pending' => true, 'confidence' => 62.0]);
        $stranger = User::factory()->create();

        Sanctum::actingAs($stranger, MobileSessionAbilities::with(['ios:read', 'ios:write']));
        $this->getJson('/api/v1/mobile/flint/review')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/mobile/flint/review/link_suggestion/{$link->id}", ['action' => 'confirm'])->assertNotFound();

        $this->assertTrue($link->fresh()->isPending());
    }

    #[Test]
    public function the_web_review_tab_lists_and_acts_on_items(): void
    {
        $link = $this->link(['auto_linked' => true, 'pending' => true, 'confidence' => 62.0]);

        $this->actingAs($this->user);
        Volt::test('flint.review')
            ->assertSee('Link suggestion')
            ->assertSee('Needs your decision')
            ->assertSee('62% match score')
            ->assertSee('Pot')
            ->assertSee('Savings')
            ->call('act', 'link_suggestion', (string) $link->id, 'dismiss')
            ->assertSee('Nothing to review.');

        $this->assertSoftDeleted($link);
    }

    #[Test]
    public function the_web_review_tab_shows_receipt_and_candidate_context_before_confirming(): void
    {
        $transaction = $this->transaction('Coffee House');
        $receipt = $this->receipt('Coffee House', ['needs_review' => true, 'candidate_matches' => [
            ['transaction_id' => $transaction->id, 'confidence' => 0.7],
        ]]);

        $this->actingAs($this->user);
        Volt::test('flint.review')
            ->assertSee('Needs your decision')
            ->assertSee('Choose a transaction')
            ->assertSee('Coffee House receipt')
            ->assertSee('Coffee House')
            ->assertSee('£4.50')
            ->assertSee('70% match score')
            ->set("chosen.{$receipt->id}", $transaction->id)
            ->call('act', 'receipt_suggestion', (string) $receipt->id, 'confirm')
            ->assertSee('Nothing to review.');

        $this->assertTrue(Relationship::where('from_id', $receipt->id)->where('to_id', $transaction->id)->exists());
    }

    #[Test]
    public function review_actions_reject_a_different_kind_or_an_item_already_reviewed(): void
    {
        $link = $this->link(['auto_linked' => true, 'confidence' => 91.0]);
        Sanctum::actingAs($this->user, MobileSessionAbilities::with(['ios:read', 'ios:write']));

        $this->postJson("/api/v1/mobile/flint/review/receipt_auto_match/{$link->id}", ['action' => 'undo'])->assertNotFound();
        $this->postJson("/api/v1/mobile/flint/review/link_suggestion/{$link->id}", ['action' => 'confirm'])->assertNotFound();
        $this->assertNotSoftDeleted($link);
        $this->assertArrayNotHasKey('reviewed_at', $link->fresh()->metadata);

        $this->postJson("/api/v1/mobile/flint/review/auto_link/{$link->id}", ['action' => 'keep'])->assertOk();
        $this->postJson("/api/v1/mobile/flint/review/auto_link/{$link->id}", ['action' => 'undo'])->assertNotFound();
        $this->assertNotSoftDeleted($link);
    }

    #[Test]
    public function a_dismissed_receipt_suggestion_cannot_be_confirmed_from_a_stale_screen(): void
    {
        $transaction = $this->transaction('Coffee House');
        $receipt = $this->receipt('Coffee House', ['needs_review' => true, 'candidate_matches' => [
            ['transaction_id' => $transaction->id, 'confidence' => 0.7],
        ]]);
        Sanctum::actingAs($this->user, MobileSessionAbilities::with(['ios:read', 'ios:write']));

        $this->postJson("/api/v1/mobile/flint/review/receipt_suggestion/{$receipt->id}", ['action' => 'dismiss'])->assertOk();
        $this->postJson("/api/v1/mobile/flint/review/receipt_suggestion/{$receipt->id}", ['action' => 'confirm', 'transaction_id' => $transaction->id])->assertNotFound();
        $this->assertFalse(Relationship::where('from_id', $receipt->id)->exists());
    }

    #[Test]
    public function shared_merchant_flags_do_not_change_each_receipts_link_state(): void
    {
        $first = $this->receipt('Coffee House');
        $second = $this->receipt('Coffee House');
        $second->update(['target_id' => $first->target_id]);
        $first->target->update(['metadata' => ['is_matched' => true, 'needs_review' => true]]);
        $this->receiptLink($first, $this->transaction('Coffee House'));

        $this->assertTrue(ReceiptMatchState::isMatched($first));
        $this->assertFalse(ReceiptMatchState::isMatched($second));

        Sanctum::actingAs($this->user, MobileSessionAbilities::with(['ios:read', 'ios:write']));
        $this->getJson('/api/v1/mobile/flint/receipts/unmatched')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', (string) $second->id);
        $this->getJson("/api/v1/mobile/flint/receipts/{$first->id}/match")
            ->assertOk()->assertJsonPath('data.status', 'matched');
        $this->getJson("/api/v1/mobile/flint/receipts/{$second->id}/match")
            ->assertOk()->assertJsonPath('data.status', 'unmatched');
    }

    #[Test]
    public function mobile_user_can_retry_search_link_and_unlink_an_unmatched_receipt(): void
    {
        Queue::fake();
        $receipt = $this->receipt('Coffee House');
        $transaction = $this->transaction('Coffee House');
        Sanctum::actingAs($this->user, MobileSessionAbilities::with(['ios:read', 'ios:write']));

        $this->postJson("/api/v1/mobile/flint/receipts/{$receipt->id}/retry")
            ->assertStatus(202)->assertJsonPath('data.status', 'searching');
        Queue::assertPushed(ProcessTaskPipelineJob::class);
        $this->getJson("/api/v1/mobile/flint/receipts/{$receipt->id}/transactions?q=Coffee")
            ->assertOk()->assertJsonPath('data.0.id', (string) $transaction->id);
        $this->getJson("/api/v1/mobile/flint/receipts/{$receipt->id}/transactions?q=4.50")
            ->assertOk()->assertJsonPath('data.0.id', (string) $transaction->id);
        $this->getJson("/api/v1/mobile/flint/receipts/{$receipt->id}/transactions?q={$transaction->time->toDateString()}")
            ->assertOk()->assertJsonPath('data.0.id', (string) $transaction->id);
        $this->postJson("/api/v1/mobile/flint/receipts/{$receipt->id}/link", ['transaction_id' => $transaction->id])
            ->assertOk()->assertJsonPath('data.status', 'matched');
        $this->deleteJson("/api/v1/mobile/flint/receipts/{$receipt->id}/match")
            ->assertOk()->assertJsonPath('data.status', 'unmatched');
    }

    private function transaction(string $merchant): Event
    {
        return Event::factory()->create([
            'integration_id' => $this->bank->id,
            'service' => 'monzo',
            'domain' => 'money',
            'action' => 'card_payment_to',
            'value' => 450,
            'value_multiplier' => 100,
            'value_unit' => 'GBP',
            'target_id' => EventObject::factory()->create(['user_id' => $this->user->id, 'title' => $merchant])->id,
        ]);
    }

    private function receipt(string $merchant, array $metadata = []): Event
    {
        return Event::factory()->create([
            'integration_id' => $this->receipts->id,
            'service' => 'receipt',
            'domain' => 'money',
            'action' => 'had_receipt_from',
            'value' => 450,
            'value_multiplier' => 100,
            'value_unit' => 'GBP',
            'target_id' => EventObject::factory()->create([
                'user_id' => $this->user->id,
                'concept' => 'receipt',
                'title' => "{$merchant} receipt",
                'metadata' => [],
            ])->id,
            'event_metadata' => ['receipt_matching' => [
                'status' => ! empty($metadata['needs_review']) ? 'suggestions' : 'unmatched',
                'candidates' => $metadata['candidate_matches'] ?? [],
            ]],
        ]);
    }

    private function receiptLink(Event $receipt, Event $transaction): Relationship
    {
        return Relationship::createRelationship([
            'user_id' => $this->user->id,
            'from_type' => Event::class,
            'from_id' => $receipt->id,
            'to_type' => Event::class,
            'to_id' => $transaction->id,
            'type' => 'receipt_for',
            'metadata' => ['match_confidence' => 0.92, 'match_method' => 'automatic'],
        ]);
    }

    private function link(array $metadata): Relationship
    {
        return Relationship::createRelationship([
            'user_id' => $this->user->id,
            'from_type' => Event::class,
            'from_id' => $this->transaction('Pot')->id,
            'to_type' => Event::class,
            'to_id' => $this->transaction('Savings')->id,
            'type' => 'transferred_to',
            'metadata' => $metadata,
        ]);
    }
}
