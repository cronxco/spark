<?php

namespace Tests\Unit\Integrations;

use App\Integrations\Receipt\ReceiptTransactionMatcher;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\Relationship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceiptTransactionMatcherTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Integration $receiptIntegration;

    private Integration $monzoIntegration;

    private ReceiptTransactionMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->receiptIntegration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'receipt',
        ]);
        $this->monzoIntegration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'monzo',
        ]);
        $this->matcher = new ReceiptTransactionMatcher;
    }

    /** @test */
    public function it_finds_perfect_match_with_exact_amount_and_time()
    {
        $merchant = $this->createMerchant('Tesco');
        $receipt = $this->createReceipt($merchant, 5000, now()); // £50.00

        $transaction = $this->createTransaction('Tesco', 5000, now()->addMinutes(2));

        $candidates = $this->matcher->findCandidateMatches($receipt);

        $this->assertCount(1, $candidates);
        $topMatch = $candidates->first();
        $this->assertEquals($transaction->id, $topMatch['transaction_id']);
        $this->assertGreaterThanOrEqual(0.8, $topMatch['confidence']); // Should auto-match
    }

    /** @test */
    public function it_matches_with_amount_tolerance()
    {
        $merchant = $this->createMerchant('Sainsburys');
        $receipt = $this->createReceipt($merchant, 2500, now()); // £25.00

        // Transaction is 2% higher (within 5% tolerance)
        $transaction = $this->createTransaction('Sainsburys', 2550, now()->addMinutes(5));

        $candidates = $this->matcher->findCandidateMatches($receipt);

        $this->assertCount(1, $candidates);
        $this->assertGreaterThan(0.5, $candidates->first()['confidence']);
    }

    /** @test */
    public function it_handles_fuzzy_merchant_matching()
    {
        $merchant = $this->createMerchant('TESCO EXTRA LONDON');
        $receipt = $this->createReceipt($merchant, 3000, now());

        $transaction = $this->createTransaction('Tesco', 3000, now()->addMinutes(3));

        $candidates = $this->matcher->findCandidateMatches($receipt);

        $this->assertCount(1, $candidates);
        $this->assertGreaterThan(0.6, $candidates->first()['confidence']);
    }

    /** @test */
    public function it_considers_time_proximity_in_confidence()
    {
        $merchant = $this->createMerchant('Costa Coffee');
        $receipt = $this->createReceipt($merchant, 450, now());

        $nearTransaction = $this->createTransaction('Costa', 450, now()->addMinutes(1));
        $farTransaction = $this->createTransaction('Costa', 450, now()->addHours(2));

        $candidates = $this->matcher->findCandidateMatches($receipt);

        $this->assertCount(2, $candidates);
        $nearMatch = $candidates->firstWhere('transaction_id', $nearTransaction->id);
        $farMatch = $candidates->firstWhere('transaction_id', $farTransaction->id);

        $this->assertGreaterThan($farMatch['confidence'], $nearMatch['confidence']);
    }

    /** @test */
    public function it_boosts_confidence_with_card_match()
    {
        $merchant = $this->createMerchant('Waitrose', ['card_last_four' => '1234']);
        $receipt = $this->createReceipt($merchant, 1500, now());

        $matchingCardTxn = $this->createTransaction('Waitrose', 1500, now()->addMinutes(2), ['card_last_four' => '1234']);
        $differentCardTxn = $this->createTransaction('Waitrose', 1500, now()->addMinutes(3), ['card_last_four' => '5678']);

        $candidates = $this->matcher->findCandidateMatches($receipt);

        $matchingMatch = $candidates->firstWhere('transaction_id', $matchingCardTxn->id);
        $differentMatch = $candidates->firstWhere('transaction_id', $differentCardTxn->id);

        $this->assertGreaterThan($differentMatch['confidence'], $matchingMatch['confidence']);
    }

    /** @test */
    public function it_creates_receipt_relationship_correctly()
    {
        $merchant = $this->createMerchant('M&S');
        $receipt = $this->createReceipt($merchant, 2000, now());
        $transaction = $this->createTransaction('M&S', 2000, now()->addMinutes(1));

        $this->matcher->createReceiptRelationship($receipt, $transaction, 0.95, 'automatic');

        // Check relationship created
        $this->assertDatabaseHas('relationships', [
            'user_id' => $this->user->id,
            'from_type' => Event::class,
            'from_id' => $receipt->id,
            'to_type' => Event::class,
            'to_id' => $transaction->id,
            'type' => 'receipt_for',
        ]);

        // Check merchant metadata updated
        $merchant->refresh();
        $this->assertTrue($merchant->metadata['is_matched']);
        $this->assertEquals($transaction->id, $merchant->metadata['matched_transaction_id']);
        $this->assertEquals(0.95, $merchant->metadata['match_confidence']);
        $this->assertEquals('automatic', $merchant->metadata['match_method']);
    }

    /** @test */
    public function it_flags_candidates_for_review()
    {
        $merchant = $this->createMerchant('Asda');
        $receipt = $this->createReceipt($merchant, 3500, now());

        $candidate1 = $this->createTransaction('Asda', 3500, now()->addMinutes(5));
        $candidate2 = $this->createTransaction('Asda', 3550, now()->addMinutes(10));
        $candidate3 = $this->createTransaction('Asda', 3450, now()->addMinutes(15));

        $this->matcher->flagForReview($receipt, collect([
            ['transaction_id' => $candidate1->id, 'confidence' => 0.75],
            ['transaction_id' => $candidate2->id, 'confidence' => 0.65],
            ['transaction_id' => $candidate3->id, 'confidence' => 0.60],
        ]));

        $merchant->refresh();
        $this->assertTrue($merchant->metadata['needs_review']);
        $this->assertCount(3, $merchant->metadata['match_candidates']);
        $this->assertEquals(0.75, $merchant->metadata['match_candidates'][0]['confidence']);
    }

    /** @test */
    public function it_excludes_transactions_from_other_users()
    {
        $otherUser = User::factory()->create();
        $otherIntegration = Integration::factory()->create([
            'user_id' => $otherUser->id,
            'service' => 'monzo',
        ]);

        $merchant = $this->createMerchant('Lidl');
        $receipt = $this->createReceipt($merchant, 1000, now());

        // Create transaction for other user
        $otherMerchant = EventObject::factory()->create([
            'user_id' => $otherUser->id,
            'concept' => 'merchant',
            'type' => 'card_payment_to',
            'title' => 'Lidl',
        ]);

        Event::factory()->create([
            'user_id' => $otherUser->id,
            'integration_id' => $otherIntegration->id,
            'service' => 'monzo',
            'domain' => 'money',
            'action' => 'card_payment_to',
            'target_id' => $otherMerchant->id,
            'value' => 1000,
            'value_multiplier' => 100,
            'value_unit' => 'GBP',
            'time' => now()->addMinutes(1),
        ]);

        $candidates = $this->matcher->findCandidateMatches($receipt);

        $this->assertCount(0, $candidates);
    }

    /** @test */
    public function it_supports_gocardless_transactions()
    {
        $gcIntegration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'gocardless',
        ]);

        $merchant = $this->createMerchant('Aldi');
        $receipt = $this->createReceipt($merchant, 2500, now());

        $gcMerchant = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'merchant',
            'type' => 'payment_to',
            'title' => 'Aldi',
        ]);

        $gcTransaction = Event::factory()->create([
            'user_id' => $this->user->id,
            'integration_id' => $gcIntegration->id,
            'service' => 'gocardless',
            'domain' => 'money',
            'action' => 'payment_to',
            'target_id' => $gcMerchant->id,
            'value' => 2500,
            'value_multiplier' => 100,
            'value_unit' => 'GBP',
            'time' => now()->addMinutes(3),
        ]);

        $candidates = $this->matcher->findCandidateMatches($receipt);

        $this->assertCount(1, $candidates);
        $this->assertEquals($gcTransaction->id, $candidates->first()['transaction_id']);
    }

    /** @test */
    public function it_respects_time_window()
    {
        $merchant = $this->createMerchant('Greggs');
        $receipt = $this->createReceipt($merchant, 500, now());

        // Transaction too early (25 hours before)
        $tooEarly = $this->createTransaction('Greggs', 500, now()->subHours(25));

        // Transaction too late (25 hours after)
        $tooLate = $this->createTransaction('Greggs', 500, now()->addHours(25));

        // Transaction within window
        $withinWindow = $this->createTransaction('Greggs', 500, now()->addHours(2));

        $candidates = $this->matcher->findCandidateMatches($receipt);

        $this->assertCount(1, $candidates);
        $this->assertEquals($withinWindow->id, $candidates->first()['transaction_id']);
    }

    private function createMerchant(string $name, array $metadata = []): EventObject
    {
        return EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'merchant',
            'type' => 'receipt_received_from',
            'title' => $name,
            'metadata' => array_merge([
                'is_matched' => false,
                'extracted_data' => [
                    'merchant_name' => $name,
                ],
            ], $metadata),
        ]);
    }

    private function createReceipt(EventObject $merchant, int $amount, $time): Event
    {
        return Event::factory()->create([
            'user_id' => $this->user->id,
            'integration_id' => $this->receiptIntegration->id,
            'service' => 'receipt',
            'domain' => 'money',
            'action' => 'receipt_received_from',
            'target_id' => $merchant->id,
            'value' => $amount,
            'value_multiplier' => 100,
            'value_unit' => 'GBP',
            'time' => $time,
        ]);
    }

    private function createTransaction(string $merchantName, int $amount, $time, array $metadata = []): Event
    {
        $merchant = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'merchant',
            'type' => 'card_payment_to',
            'title' => $merchantName,
            'metadata' => $metadata,
        ]);

        return Event::factory()->create([
            'user_id' => $this->user->id,
            'integration_id' => $this->monzoIntegration->id,
            'service' => 'monzo',
            'domain' => 'money',
            'action' => 'card_payment_to',
            'target_id' => $merchant->id,
            'value' => $amount,
            'value_multiplier' => 100,
            'value_unit' => 'GBP',
            'time' => $time,
        ]);
    }
}
