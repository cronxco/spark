<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Receipt\ReceiptTransactionMatcher;
use App\Models\Event;
use App\Models\Integration;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PSEC-02b / ENR-01.
 *
 * Receipt matching searched for candidate transactions with no user predicate
 * in either direction, so one tenant's receipt could be matched to another
 * tenant's transaction — and the resulting Relationship was then stamped with
 * the receipt owner's user_id while pointing at a foreign Event.
 */
class ReceiptMatchingTenancyTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = User::factory()->create();
        $this->bob = User::factory()->create();
    }

    #[Test]
    public function a_receipt_does_not_match_another_users_transaction(): void
    {
        $at = now()->subHour();

        $this->transactionFor($this->bob, 1250, $at);
        $receipt = $this->receiptFor($this->alice, 1250, $at);

        $candidates = app(ReceiptTransactionMatcher::class)->findCandidateMatches($receipt);

        $this->assertCount(0, $candidates);
    }

    #[Test]
    public function a_receipt_still_matches_its_own_users_transaction(): void
    {
        $at = now()->subHour();

        $own = $this->transactionFor($this->alice, 1250, $at);
        $receipt = $this->receiptFor($this->alice, 1250, $at);

        $candidates = app(ReceiptTransactionMatcher::class)->findCandidateMatches($receipt);

        // Each candidate is ['transaction' => Event, 'confidence' => float, 'source' => string].
        $this->assertCount(1, $candidates);
        $this->assertSame($own->id, $candidates->first()['transaction']->id);
    }

    private function integrationFor(User $user, string $service): Integration
    {
        return Integration::factory()->create([
            'user_id' => $user->id,
            'service' => $service,
        ]);
    }

    private function transactionFor(User $user, int $value, DateTimeInterface $at): Event
    {
        return Event::factory()->create([
            'integration_id' => $this->integrationFor($user, 'monzo')->id,
            'service' => 'monzo',
            'domain' => 'money',
            'action' => 'card_payment_to',
            'value' => $value,
            'time' => $at,
        ]);
    }

    private function receiptFor(User $user, int $value, DateTimeInterface $at): Event
    {
        return Event::factory()->create([
            'integration_id' => $this->integrationFor($user, 'receipt')->id,
            'service' => 'receipt',
            'domain' => 'money',
            'action' => 'had_receipt_from',
            'value' => $value,
            'time' => $at,
            'event_metadata' => [
                'matching_hints' => [
                    'suggested_amount' => $value,
                    'suggested_date_range' => [
                        'start' => $at->format('c'),
                        'end' => $at->format('c'),
                    ],
                ],
            ],
        ]);
    }
}
