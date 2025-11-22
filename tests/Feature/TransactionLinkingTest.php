<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\PendingTransactionLink;
use App\Models\Relationship;
use App\Models\User;
use App\Services\TransactionLinking\Strategies\BacsRecordStrategy;
use App\Services\TransactionLinking\Strategies\CrossProviderStrategy;
use App\Services\TransactionLinking\Strategies\ExplicitReferenceStrategy;
use App\Services\TransactionLinking\TransactionLinkingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

class TransactionLinkingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->integration = Integration::factory()->create(['user_id' => $this->user->id]);
    }

    // ==========================================
    // ExplicitReferenceStrategy Tests
    // ==========================================

    /** @test */
    public function explicit_strategy_finds_transaction_id_reference(): void
    {
        $strategy = new ExplicitReferenceStrategy;

        // Create the target event
        $targetEvent = $this->createMonzoEvent([
            'source_id' => 'tx_00001234',
            'action' => 'card_payment_to',
            'value' => -1000,
        ]);

        // Create the referencing event (e.g., cashback)
        $sourceEvent = $this->createMonzoEvent([
            'action' => 'cashback_reward_from',
            'value' => 100,
            'event_metadata' => [
                'raw' => [
                    'metadata' => [
                        'transaction_id' => 'tx_00001234',
                    ],
                ],
            ],
        ]);

        $this->assertTrue($strategy->canProcess($sourceEvent));

        $links = $strategy->findLinks($sourceEvent);

        $this->assertCount(1, $links);
        $this->assertEquals($targetEvent->id, $links->first()['target_event']->id);
        $this->assertEquals('triggered_by', $links->first()['relationship_type']);
        $this->assertEquals(100.0, $links->first()['confidence']);
    }

    /** @test */
    public function explicit_strategy_finds_coin_jar_reference(): void
    {
        $strategy = new ExplicitReferenceStrategy;

        // Create the original card payment
        $cardPayment = $this->createMonzoEvent([
            'source_id' => 'tx_original_payment',
            'action' => 'card_payment_to',
            'value' => -1299,
        ]);

        // Create the coin jar transaction referencing it
        $coinJar = $this->createMonzoEvent([
            'action' => 'pot_deposit_from',
            'value' => -1,
            'event_metadata' => [
                'raw' => [
                    'metadata' => [
                        'coin_jar_transaction' => 'tx_original_payment',
                    ],
                ],
            ],
        ]);

        $links = $strategy->findLinks($coinJar);

        $this->assertCount(1, $links);
        $this->assertEquals($cardPayment->id, $links->first()['target_event']->id);
    }

    /** @test */
    public function explicit_strategy_finds_triggered_by_reference(): void
    {
        $strategy = new ExplicitReferenceStrategy;

        // Create the triggering transaction
        $trigger = $this->createMonzoEvent([
            'source_id' => 'tx_trigger_event',
            'action' => 'card_payment_to',
            'value' => -500,
        ]);

        // Create the triggered transaction
        $triggered = $this->createMonzoEvent([
            'action' => 'reward_from',
            'value' => 50,
            'event_metadata' => [
                'raw' => [
                    'metadata' => [
                        'triggered_by' => 'tx_trigger_event',
                    ],
                ],
            ],
        ]);

        $links = $strategy->findLinks($triggered);

        $this->assertCount(1, $links);
        $this->assertEquals($trigger->id, $links->first()['target_event']->id);
    }

    /** @test */
    public function explicit_strategy_ignores_non_tx_references(): void
    {
        $strategy = new ExplicitReferenceStrategy;

        // Create event with non-tx reference (should be ignored)
        $event = $this->createMonzoEvent([
            'event_metadata' => [
                'raw' => [
                    'metadata' => [
                        'triggered_by' => 'direct-debit', // Not a tx_ ID
                    ],
                ],
            ],
        ]);

        $links = $strategy->findLinks($event);

        $this->assertCount(0, $links);
    }

    /** @test */
    public function explicit_strategy_only_processes_monzo_events_with_metadata(): void
    {
        $strategy = new ExplicitReferenceStrategy;

        $monzoEventWithMetadata = $this->createMonzoEvent([
            'event_metadata' => ['raw' => ['some' => 'data']],
        ]);
        $monzoEventWithoutMetadata = $this->createMonzoEvent([
            'event_metadata' => [],
        ]);
        $otherEvent = $this->createEvent([
            'service' => 'other_service',
            'event_metadata' => ['raw' => ['some' => 'data']],
        ]);

        $this->assertTrue($strategy->canProcess($monzoEventWithMetadata));
        $this->assertFalse($strategy->canProcess($monzoEventWithoutMetadata));
        $this->assertFalse($strategy->canProcess($otherEvent));
    }

    // ==========================================
    // BacsRecordStrategy Tests
    // ==========================================

    /** @test */
    public function bacs_strategy_finds_pot_withdrawal_for_direct_debit(): void
    {
        $strategy = new BacsRecordStrategy;

        // Create direct debit with bacs_record_id
        $directDebit = $this->createMonzoEvent([
            'action' => 'direct_debit_to',
            'value' => -5000,
            'event_metadata' => [
                'raw' => [
                    'metadata' => [
                        'bacs_record_id' => 'bacsrcd_ABC123',
                    ],
                ],
            ],
        ]);

        // Create pot withdrawal referencing the BACS record
        $potWithdrawal = $this->createMonzoEvent([
            'action' => 'pot_withdrawal_to',
            'value' => 5000,
            'event_metadata' => [
                'raw' => [
                    'metadata' => [
                        'external_id' => 'dd-withdrawal:bacsrcd_ABC123#pot_XYZ#bacspaymentevent_123',
                    ],
                ],
            ],
        ]);

        $this->assertTrue($strategy->canProcess($directDebit));

        $links = $strategy->findLinks($directDebit);

        $this->assertCount(1, $links);
        $this->assertEquals($potWithdrawal->id, $links->first()['target_event']->id);
        $this->assertEquals('funded_by', $links->first()['relationship_type']);
        $this->assertEquals(100.0, $links->first()['confidence']);
    }

    /** @test */
    public function bacs_strategy_finds_direct_debit_from_pot_withdrawal(): void
    {
        $strategy = new BacsRecordStrategy;

        // Create direct debit with bacs_record_id
        $directDebit = $this->createMonzoEvent([
            'action' => 'direct_debit_to',
            'value' => -5000,
            'event_metadata' => [
                'raw' => [
                    'metadata' => [
                        'bacs_record_id' => 'bacsrcd_DEF456',
                    ],
                ],
            ],
        ]);

        // Create pot withdrawal referencing the BACS record
        $potWithdrawal = $this->createMonzoEvent([
            'action' => 'pot_withdrawal_to',
            'value' => 5000,
            'event_metadata' => [
                'raw' => [
                    'metadata' => [
                        'external_id' => 'dd-withdrawal:bacsrcd_DEF456#pot_XYZ',
                    ],
                ],
            ],
        ]);

        $this->assertTrue($strategy->canProcess($potWithdrawal));

        $links = $strategy->findLinks($potWithdrawal);

        $this->assertCount(1, $links);
        $this->assertEquals($directDebit->id, $links->first()['target_event']->id);
    }

    /** @test */
    public function bacs_strategy_only_processes_monzo_with_bacs_metadata(): void
    {
        $strategy = new BacsRecordStrategy;

        $eventWithBacs = $this->createMonzoEvent([
            'event_metadata' => [
                'raw' => [
                    'metadata' => [
                        'bacs_record_id' => 'bacsrcd_TEST',
                    ],
                ],
            ],
        ]);

        $eventWithExternalId = $this->createMonzoEvent([
            'event_metadata' => [
                'raw' => [
                    'metadata' => [
                        'external_id' => 'dd-withdrawal:bacsrcd_TEST',
                    ],
                ],
            ],
        ]);

        $eventWithoutBacs = $this->createMonzoEvent([
            'event_metadata' => [
                'raw' => [
                    'metadata' => [],
                ],
            ],
        ]);

        $this->assertTrue($strategy->canProcess($eventWithBacs));
        $this->assertTrue($strategy->canProcess($eventWithExternalId));
        $this->assertFalse($strategy->canProcess($eventWithoutBacs));
    }

    // ==========================================
    // CrossProviderStrategy Tests
    // ==========================================

    /** @test */
    public function cross_provider_strategy_identifies_credit_card_payment(): void
    {
        $strategy = new CrossProviderStrategy;

        $amexPayment = $this->createEvent([
            'action' => 'direct_debit_to',
            'event_metadata' => [
                'raw' => [
                    'description' => 'AMERICAN EXPRESS ************4006',
                ],
            ],
        ]);

        $this->assertTrue($strategy->canProcess($amexPayment));
    }

    /** @test */
    public function cross_provider_strategy_rejects_non_credit_card_payments(): void
    {
        $strategy = new CrossProviderStrategy;

        $normalPayment = $this->createEvent([
            'action' => 'card_payment_to',
            'event_metadata' => [
                'raw' => [
                    'description' => 'Tesco Stores',
                ],
            ],
        ]);

        $this->assertFalse($strategy->canProcess($normalPayment));
    }

    /** @test */
    public function cross_provider_strategy_extracts_card_identification(): void
    {
        $strategy = new CrossProviderStrategy;

        $event = $this->createEvent([
            'action' => 'direct_debit_to',
            'event_metadata' => [
                'raw' => [
                    'description' => 'AMERICAN EXPRESS ************4006',
                ],
            ],
        ]);

        // Use reflection to test private method
        $reflection = new ReflectionClass($strategy);
        $method = $reflection->getMethod('extractCardIdentification');
        $method->setAccessible(true);

        $cardId = $method->invoke($strategy, $event);

        $this->assertEquals('4006', $cardId);
    }

    // ==========================================
    // TransactionLinkingService Tests
    // ==========================================

    /** @test */
    public function service_creates_pending_link_for_low_confidence_match(): void
    {
        $service = app(TransactionLinkingService::class);

        // Create events with explicit reference (100% confidence -> auto-approved)
        $targetEvent = $this->createMonzoEvent([
            'source_id' => 'tx_service_test',
            'action' => 'card_payment_to',
        ]);

        $sourceEvent = $this->createMonzoEvent([
            'action' => 'reward_from',
            'event_metadata' => [
                'raw' => [
                    'metadata' => [
                        'transaction_id' => 'tx_service_test',
                    ],
                ],
            ],
        ]);

        $result = $service->processEvent($sourceEvent);

        // High confidence matches should be auto-approved
        $this->assertEquals(1, $result['auto_approved']);
        $this->assertEquals(0, $result['pending']);

        // Check relationship was created
        $this->assertDatabaseHas('relationships', [
            'user_id' => $this->user->id,
            'from_type' => Event::class,
            'from_id' => $sourceEvent->id,
            'to_type' => Event::class,
            'to_id' => $targetEvent->id,
            'type' => 'triggered_by',
        ]);
    }

    /** @test */
    public function service_returns_stats(): void
    {
        $service = app(TransactionLinkingService::class);

        // Create some pending links
        PendingTransactionLink::create([
            'user_id' => $this->user->id,
            'source_event_id' => $this->createMonzoEvent()->id,
            'target_event_id' => $this->createMonzoEvent()->id,
            'relationship_type' => 'triggered_by',
            'confidence' => 90,
            'detection_strategy' => 'test',
            'status' => 'pending',
        ]);

        PendingTransactionLink::create([
            'user_id' => $this->user->id,
            'source_event_id' => $this->createMonzoEvent()->id,
            'target_event_id' => $this->createMonzoEvent()->id,
            'relationship_type' => 'funded_by',
            'confidence' => 60,
            'detection_strategy' => 'test',
            'status' => 'pending',
        ]);

        PendingTransactionLink::create([
            'user_id' => $this->user->id,
            'source_event_id' => $this->createMonzoEvent()->id,
            'target_event_id' => $this->createMonzoEvent()->id,
            'relationship_type' => 'payment_for',
            'confidence' => 40,
            'detection_strategy' => 'test',
            'status' => 'pending',
        ]);

        $stats = $service->getPendingStats($this->user->id);

        $this->assertEquals(3, $stats['total']);
        $this->assertEquals(1, $stats['by_confidence']['high']);
        $this->assertEquals(1, $stats['by_confidence']['medium']);
        $this->assertEquals(1, $stats['by_confidence']['low']);
    }

    // ==========================================
    // PendingTransactionLink Model Tests
    // ==========================================

    /** @test */
    public function pending_link_can_be_approved(): void
    {
        $sourceEvent = $this->createMonzoEvent();
        $targetEvent = $this->createMonzoEvent();

        $pendingLink = PendingTransactionLink::create([
            'user_id' => $this->user->id,
            'source_event_id' => $sourceEvent->id,
            'target_event_id' => $targetEvent->id,
            'relationship_type' => 'triggered_by',
            'confidence' => 75,
            'detection_strategy' => 'test',
            'status' => 'pending',
        ]);

        $this->assertTrue($pendingLink->isPending());

        $pendingLink->approve();

        $this->assertEquals('approved', $pendingLink->fresh()->status);
        $this->assertNotNull($pendingLink->fresh()->approved_at);

        // Check relationship was created
        $this->assertDatabaseHas('relationships', [
            'user_id' => $this->user->id,
            'from_type' => Event::class,
            'from_id' => $sourceEvent->id,
            'to_type' => Event::class,
            'to_id' => $targetEvent->id,
            'type' => 'triggered_by',
        ]);
    }

    /** @test */
    public function pending_link_can_be_rejected(): void
    {
        $sourceEvent = $this->createMonzoEvent();
        $targetEvent = $this->createMonzoEvent();

        $pendingLink = PendingTransactionLink::create([
            'user_id' => $this->user->id,
            'source_event_id' => $sourceEvent->id,
            'target_event_id' => $targetEvent->id,
            'relationship_type' => 'triggered_by',
            'confidence' => 75,
            'detection_strategy' => 'test',
            'status' => 'pending',
        ]);

        $pendingLink->reject();

        $this->assertEquals('rejected', $pendingLink->fresh()->status);
        $this->assertNotNull($pendingLink->fresh()->rejected_at);

        // Verify no relationship was created
        $this->assertDatabaseMissing('relationships', [
            'from_id' => $sourceEvent->id,
            'to_id' => $targetEvent->id,
        ]);
    }

    /** @test */
    public function pending_link_scopes_work_correctly(): void
    {
        $sourceEvent = $this->createMonzoEvent();
        $targetEvent = $this->createMonzoEvent();

        PendingTransactionLink::create([
            'user_id' => $this->user->id,
            'source_event_id' => $sourceEvent->id,
            'target_event_id' => $targetEvent->id,
            'relationship_type' => 'triggered_by',
            'confidence' => 90,
            'detection_strategy' => 'explicit_reference',
            'status' => 'pending',
        ]);

        PendingTransactionLink::create([
            'user_id' => $this->user->id,
            'source_event_id' => $this->createMonzoEvent()->id,
            'target_event_id' => $this->createMonzoEvent()->id,
            'relationship_type' => 'funded_by',
            'confidence' => 50,
            'detection_strategy' => 'bacs_record',
            'status' => 'approved',
        ]);

        $this->assertEquals(1, PendingTransactionLink::pending()->count());
        $this->assertEquals(1, PendingTransactionLink::aboveConfidence(80)->count());
        $this->assertEquals(1, PendingTransactionLink::forStrategy('explicit_reference')->count());
    }

    /** @test */
    public function pending_link_stores_matching_criteria(): void
    {
        $sourceEvent = $this->createMonzoEvent();
        $targetEvent = $this->createMonzoEvent();

        $criteria = [
            'type' => 'transaction_id',
            'path' => 'raw.metadata.transaction_id',
            'referenced_id' => 'tx_12345',
        ];

        $pendingLink = PendingTransactionLink::create([
            'user_id' => $this->user->id,
            'source_event_id' => $sourceEvent->id,
            'target_event_id' => $targetEvent->id,
            'relationship_type' => 'triggered_by',
            'confidence' => 100,
            'detection_strategy' => 'explicit_reference',
            'matching_criteria' => $criteria,
            'status' => 'pending',
        ]);

        $this->assertEquals($criteria, $pendingLink->matching_criteria);
    }

    // ==========================================
    // Helper Methods
    // ==========================================

    private function createMonzoEvent(array $attributes = []): Event
    {
        return $this->createEvent(array_merge(['service' => 'monzo'], $attributes));
    }

    private function createEvent(array $attributes = []): Event
    {
        $actor = EventObject::factory()->create(['user_id' => $this->user->id]);
        $target = EventObject::factory()->create(['user_id' => $this->user->id]);

        return Event::create(array_merge([
            'id' => fake()->uuid(),
            'source_id' => fake()->uuid(),
            'time' => now(),
            'integration_id' => $this->integration->id,
            'actor_id' => $actor->id,
            'target_id' => $target->id,
            'service' => 'test',
            'domain' => 'money',
            'action' => 'test_action',
            'value' => 0,
            'value_multiplier' => 100,
            'value_unit' => 'GBP',
            'event_metadata' => [],
        ], $attributes));
    }
}
