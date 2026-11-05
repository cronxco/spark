<?php

namespace Tests\Feature;

use App\Integrations\GoCardless\GoCardlessBankPlugin;
use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GoCardlessTransactionIdentityTest extends TestCase
{
    private User $owner;

    private Integration $integration;

    private GoCardlessBankPlugin $plugin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.enable_task_pipeline' => false]);
        Queue::fake();
        Cache::flush();
        Http::preventStrayRequests();
        $this->owner = User::factory()->create();
        $this->integration = $this->integration();
        $this->plugin = new class extends GoCardlessBankPlugin
        {
            public function getAccessToken(): string
            {
                return 'test-token';
            }
        };
    }

    #[Test]
    public function the_same_bank_transaction_with_changed_merchant_text_and_time_stays_one_event(): void
    {
        $this->process($this->qantas('QANTAS AIRWAYS LIMITED MAIL/TEL SALES'));
        $first = Event::where('integration_id', $this->integration->id)->firstOrFail();

        $this->process($this->qantas('QANTAS AIRWAYS LIMITED MAILTEL SALES', '2026-09-08T19:53:03Z'));

        $this->assertSame(1, Event::where('integration_id', $this->integration->id)->count());
        $this->assertSame($first->id, Event::where('integration_id', $this->integration->id)->firstOrFail()->id);
        $this->assertSame($first->source_id, Event::where('integration_id', $this->integration->id)->firstOrFail()->source_id);
    }

    #[Test]
    public function a_renewed_connection_does_not_duplicate_history_it_already_has(): void
    {
        $this->process($this->qantas('QANTAS AIRWAYS LIMITED MAIL/TEL SALES'));
        $renewed = $this->integration();

        $this->plugin->processTransactionItem($renewed, $this->account(), $this->qantas('QANTAS AIRWAYS MAILTEL'), 'booked');

        $this->assertSame(1, Event::forUser($this->owner->id)->where('service', 'gocardless')->count());
    }

    #[Test]
    public function reimporting_the_same_page_is_idempotent(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->process($this->qantas('QANTAS AIRWAYS LIMITED MAIL/TEL SALES'));
        }

        $this->assertSame(1, Event::where('integration_id', $this->integration->id)->count());
    }

    #[Test]
    public function two_purchases_of_the_same_amount_at_the_same_shop_on_the_same_day_are_both_kept(): void
    {
        $this->process($this->sainsburys('AT-1'));
        $this->process($this->sainsburys('AT-2'));
        $this->process($this->sainsburys('AT-2'));

        $this->assertSame(2, Event::where('integration_id', $this->integration->id)->count());
    }

    #[Test]
    public function a_booked_line_with_no_bank_id_still_matches_by_content(): void
    {
        $tx = $this->sainsburys('AT-1');
        unset($tx['transactionId'], $tx['internalTransactionId']);
        $this->process($tx);
        $this->process($tx);

        $this->assertSame(1, Event::where('integration_id', $this->integration->id)->count());
    }

    private function integration(): Integration
    {
        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->owner->id, 'service' => 'gocardless', 'account_id' => 'requisition-' . uniqid(),
        ]);

        return Integration::factory()->create([
            'user_id' => $this->owner->id, 'integration_group_id' => $group->id,
            'service' => 'gocardless', 'instance_type' => 'transactions',
            'configuration' => ['account_id' => 'account-1'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $tx
     */
    private function process(array $tx, string $status = 'booked'): void
    {
        $this->plugin->processTransactionItem($this->integration, $this->account(), $tx, $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function account(): array
    {
        return ['id' => 'account-1', 'details' => 'Amex', 'resourceId' => 'resource-1', 'currency' => 'GBP', 'cashAccountType' => 'CARD'];
    }

    /**
     * @return array<string, mixed>
     */
    private function qantas(string $creditor, string $bookingDateTime = '2026-09-08T00:00:00Z'): array
    {
        return [
            'transactionId' => 'AT262530048000010959713---0005---20260908',
            'internalTransactionId' => '4d2ed423c119ca12080e8d5f3226dcd8',
            'bookingDate' => '2026-09-08', 'valueDate' => '2026-09-10', 'bookingDateTime' => $bookingDateTime,
            'creditorName' => $creditor, 'remittanceInformationUnstructured' => 'QANTAS                  MASCOT',
            'transactionAmount' => ['amount' => '-6977.79', 'currency' => 'GBP'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sainsburys(string $id): array
    {
        return [
            'transactionId' => $id, 'internalTransactionId' => 'i-' . $id,
            'bookingDate' => '2026-09-28', 'valueDate' => '2026-09-29', 'creditorName' => 'SAINSBURYS',
            'remittanceInformationUnstructured' => "SAINSBURY'S LOCAL WESMI LONDON",
            'transactionAmount' => ['amount' => '-3.95', 'currency' => 'GBP'],
        ];
    }
}
