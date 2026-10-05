<?php

namespace Tests\Feature\Enrichment;

use App\Integrations\Receipt\ReceiptTransactionMatcher;
use App\Jobs\Receipt\ReviewUnmatchedReceiptJob;
use App\Jobs\TaskPipeline\Tasks\FindReceiptForTransactionTask;
use App\Jobs\TaskPipeline\Tasks\MatchReceiptToTransactionTask;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\Relationship;
use App\Models\TaskExecution;
use App\Models\User;
use App\Services\Receipt\ReceiptMatchState;
use App\Services\Receipt\ReceiptMatchingActions;
use App\Services\TaskPipeline\TaskDefinition;
use App\Services\TaskPipeline\TaskRegistry;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ENR-01 / ENR-03 / ENR-04: reverse matching runs from the Task Pipeline on
 * the actions Monzo and GoCardless really send, links only same-owner
 * events, and records what each run achieved.
 */
class ReceiptMatchingPipelineTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.enable_task_pipeline' => false]);

        $this->alice = User::factory()->create();
    }

    #[Test]
    public function reverse_matching_applies_to_the_real_transaction_actions(): void
    {
        $task = TaskRegistry::getTask('find_receipt_for_transaction');

        foreach (['card_payment_to', 'payment_to', 'made_transaction', 'card_refund_from', 'payment_from'] as $action) {
            $this->assertTrue(
                $task->isApplicableTo($this->transactionFor($this->alice, 1250, now(), $action)),
                "{$action} should look for a receipt",
            );
        }

        $this->assertFalse($task->isApplicableTo($this->transactionFor($this->alice, 1250, now(), 'balance_update')));
    }

    #[Test]
    public function a_new_transaction_links_its_owners_receipt(): void
    {
        $at = now()->subHour();
        $receipt = $this->receiptFor($this->alice, 1250, $at, 'Coffee Shop');
        $transaction = $this->transactionFor($this->alice, 1250, $at, 'card_payment_to', 'Coffee Shop');

        (new FindReceiptForTransactionTask($transaction, $this->definition('find_receipt_for_transaction', FindReceiptForTransactionTask::class)))->handle();

        $this->assertTrue(Relationship::where([
            'from_id' => $receipt->id,
            'to_id' => $transaction->id,
            'type' => 'receipt_for',
            'user_id' => $this->alice->id,
        ])->exists());
        $this->assertSame('matched', $this->outcome($transaction, 'find_receipt_for_transaction'));
    }

    #[Test]
    public function a_transaction_with_no_receipt_records_no_candidate(): void
    {
        $transaction = $this->transactionFor($this->alice, 1250, now(), 'card_payment_to');

        (new FindReceiptForTransactionTask($transaction, $this->definition('find_receipt_for_transaction', FindReceiptForTransactionTask::class)))->handle();

        $this->assertSame('no_candidate', $this->outcome($transaction, 'find_receipt_for_transaction'));
    }

    #[Test]
    public function forward_matching_records_no_candidate(): void
    {
        $receipt = $this->receiptFor($this->alice, 1250, now()->subHour(), 'Coffee Shop');

        (new MatchReceiptToTransactionTask($receipt, $this->definition('match_receipt_to_transaction', MatchReceiptToTransactionTask::class)))->handle();

        $this->assertSame('no_candidate', $this->outcome($receipt, 'match_receipt_to_transaction'));
    }

    #[Test]
    public function forward_matching_records_a_match(): void
    {
        $at = now()->subHour();
        $this->transactionFor($this->alice, 1250, $at, 'card_payment_to', 'Coffee Shop');
        $receipt = $this->receiptFor($this->alice, 1250, $at, 'Coffee Shop');

        (new MatchReceiptToTransactionTask($receipt, $this->definition('match_receipt_to_transaction', MatchReceiptToTransactionTask::class)))->handle();

        $this->assertContains($this->outcome($receipt, 'match_receipt_to_transaction'), ['matched', 'review_required']);
    }

    #[Test]
    public function a_receipt_cannot_be_linked_to_another_users_transaction(): void
    {
        $at = now()->subHour();
        $receipt = $this->receiptFor($this->alice, 1250, $at, 'Coffee Shop');
        $foreign = $this->transactionFor(User::factory()->create(), 1250, $at, 'card_payment_to', 'Coffee Shop');

        try {
            app(ReceiptTransactionMatcher::class)->createReceiptRelationship($receipt, $foreign, 0.95, 'automatic');
            $this->fail('Expected a cross-owner link to be refused.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertFalse(Relationship::where('type', 'receipt_for')->exists());
    }

    #[Test]
    public function a_receipt_cannot_gain_a_second_active_transaction_link(): void
    {
        $receipt = $this->receiptFor($this->alice, 1250, now(), 'Coffee Shop');
        $first = $this->transactionFor($this->alice, 1250, now(), 'card_payment_to', 'Coffee Shop');
        $second = $this->transactionFor($this->alice, 1250, now(), 'card_payment_to', 'Coffee Shop');
        $matcher = app(ReceiptTransactionMatcher::class);

        $link = $matcher->createReceiptRelationship($receipt, $first, 0.9, 'automatic');
        $this->assertSame($link->id, $matcher->createReceiptRelationship($receipt, $first, 0.9, 'automatic')->id);

        $this->expectException(InvalidArgumentException::class);
        try {
            $matcher->createReceiptRelationship($receipt, $second, 0.9, 'automatic');
        } finally {
            $this->assertSame(1, ReceiptMatchState::links()->where('from_id', $receipt->id)->count());
        }
    }

    #[Test]
    public function missing_hints_can_produce_suggestions_without_an_automatic_link(): void
    {
        $at = now()->subHour();
        $receipt = $this->receiptFor($this->alice, 1250, $at, 'Coffee Shop');
        $receipt->update(['event_metadata' => []]);
        $this->transactionFor($this->alice, 1250, $at, 'card_payment_to', 'Coffee Shop');

        $outcome = app(ReceiptTransactionMatcher::class)->matchReceipt($receipt);

        $this->assertSame('review_required', $outcome);
        $this->assertFalse(ReceiptMatchState::isMatched($receipt));
        $this->assertNotEmpty(ReceiptMatchState::candidates($receipt->fresh()));
    }

    #[Test]
    public function backfill_preview_is_read_only_and_dispatched_batch_only_makes_suggestions(): void
    {
        Queue::fake();
        $at = now()->subHour();
        $receipt = $this->receiptFor($this->alice, 1250, $at, 'Coffee Shop');
        $this->transactionFor($this->alice, 1250, $at, 'card_payment_to', 'Coffee Shop');

        $this->assertSame(0, Artisan::call('receipt-matching:backfill', ['--limit' => 1]));
        $this->assertSame([], ReceiptMatchState::state($receipt->fresh()));
        Queue::assertNotPushed(ReviewUnmatchedReceiptJob::class);

        $this->assertSame(0, Artisan::call('receipt-matching:backfill', ['--dispatch' => true, '--limit' => 1]));
        Queue::assertPushed(ReviewUnmatchedReceiptJob::class, 1);
        $job = Queue::pushed(ReviewUnmatchedReceiptJob::class)->first();
        $job->handle(app(ReceiptTransactionMatcher::class));

        $this->assertSame('suggestions', ReceiptMatchState::status($receipt->fresh()));
        $this->assertFalse(ReceiptMatchState::isMatched($receipt));
    }

    #[Test]
    public function queued_matching_and_failure_callbacks_preserve_user_decisions(): void
    {
        $at = now()->subHour();
        $receipt = $this->receiptFor($this->alice, 1250, $at, 'Coffee Shop');
        $this->transactionFor($this->alice, 1250, $at, 'card_payment_to', 'Coffee Shop');
        $job = new ReviewUnmatchedReceiptJob($receipt->id, 'queued-before-decision');
        $task = new MatchReceiptToTransactionTask($receipt, $this->definition('match_receipt_to_transaction', MatchReceiptToTransactionTask::class));

        foreach (['no_match', 'dismissed'] as $status) {
            ReceiptMatchState::update($receipt, ['status' => $status, 'candidates' => []]);
            $before = ReceiptMatchState::state($receipt->fresh());
            $job->handle(app(ReceiptTransactionMatcher::class));
            $this->assertSame($status, app(ReceiptTransactionMatcher::class)->matchReceipt($receipt));
            $job->failed(new \RuntimeException('Late failure'));
            $task->failed(new \RuntimeException('Late task failure'));
            $this->assertSame($before, ReceiptMatchState::state($receipt->fresh()));
            $this->assertFalse(ReceiptMatchState::isMatched($receipt));
        }
    }

    #[Test]
    public function rejected_receipts_reopen_only_for_the_qualifying_incoming_transaction(): void
    {
        $at = now()->subHour();
        $receipt = $this->receiptFor($this->alice, 1250, $at, 'Coffee Shop');
        $old = $this->transactionFor($this->alice, 1250, $at, 'card_payment_to', 'Coffee Shop');
        $weak = $this->transactionFor($this->alice, 1250, $at->copy()->addHours(4), 'card_payment_to', 'ZZZZ');
        $new = $this->transactionFor($this->alice, 1250, $at, 'card_payment_to', 'Coffee Shop');
        $matcher = app(ReceiptTransactionMatcher::class);

        foreach (['no_match', 'dismissed'] as $status) {
            ReceiptMatchState::update($receipt, ['status' => $status, 'candidates' => []]);
            $this->assertSame($status, $matcher->matchReceipt($receipt, $weak));
            $this->assertSame($status, ReceiptMatchState::status($receipt->fresh()));
            $this->assertSame('review_required', $matcher->matchReceipt($receipt, $new));
            $this->assertSame([$new->id], array_column(ReceiptMatchState::candidates($receipt->fresh()), 'transaction_id'));
            $this->assertNotContains($old->id, array_column(ReceiptMatchState::candidates($receipt), 'transaction_id'));
            $this->assertFalse(ReceiptMatchState::isMatched($receipt));
        }
    }

    #[Test]
    public function explicit_no_match_refuses_a_link_and_retry_clears_the_decision(): void
    {
        Queue::fake();
        $receipt = $this->receiptFor($this->alice, 1250, now(), 'Coffee Shop');
        $transaction = $this->transactionFor($this->alice, 1250, now(), 'card_payment_to', 'Coffee Shop');
        $actions = app(ReceiptMatchingActions::class);
        $actions->markNoMatch($receipt);
        $actions->retry($receipt);
        $this->assertSame('searching', ReceiptMatchState::status($receipt->fresh()));
        $actions->link($receipt, $transaction);
        try {
            $actions->markNoMatch($receipt);
            $this->fail('A linked receipt must reject no-match.');
        } catch (InvalidArgumentException) {
            $this->assertSame('matched', ReceiptMatchState::status($receipt->fresh()));
            $this->assertSame('matched', ReceiptMatchState::state($receipt)['status']);
        }
    }

    private function outcome(Event $event, string $taskKey): ?string
    {
        return TaskExecution::query()
            ->where('entity_id', $event->id)
            ->where('task_key', $taskKey)
            ->first()
            ?->last_success['outcome'] ?? null;
    }

    private function definition(string $key, string $jobClass): TaskDefinition
    {
        return new TaskDefinition(key: $key, name: $key, description: $key, jobClass: $jobClass, appliesTo: ['event']);
    }

    private function integrationFor(User $user, string $service): Integration
    {
        return Integration::factory()->create(['user_id' => $user->id, 'service' => $service]);
    }

    private function transactionFor(User $user, int $value, DateTimeInterface $at, string $action, string $merchant = 'Somewhere'): Event
    {
        return Event::factory()->create([
            'integration_id' => $this->integrationFor($user, 'monzo')->id,
            'service' => 'monzo',
            'domain' => 'money',
            'action' => $action,
            'value' => $value,
            'time' => $at,
            'target_id' => EventObject::factory()->create(['user_id' => $user->id, 'title' => $merchant])->id,
        ]);
    }

    private function receiptFor(User $user, int $value, DateTimeInterface $at, string $merchant): Event
    {
        return Event::factory()->create([
            'integration_id' => $this->integrationFor($user, 'receipt')->id,
            'service' => 'receipt',
            'domain' => 'money',
            'action' => 'had_receipt_from',
            'value' => $value,
            'time' => $at,
            'target_id' => EventObject::factory()->create([
                'user_id' => $user->id,
                'title' => $merchant,
                'metadata' => ['is_matched' => false],
            ])->id,
            'event_metadata' => [
                'matching_hints' => [
                    'suggested_amount' => $value,
                    'suggested_date_range' => ['start' => $at->format('c'), 'end' => $at->format('c')],
                    'merchant_name' => $merchant,
                ],
            ],
        ]);
    }
}
