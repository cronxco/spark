<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Monzo\MonzoPlugin;
use App\Jobs\Data\Monzo\MonzoBalanceData;
use App\Jobs\Data\Monzo\MonzoPotData;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class MonzoAuditRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function integration(string $type): Integration
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'monzo',
            'access_token' => 'test-token',
        ]);

        return Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'monzo',
            'instance_type' => $type,
        ]);
    }

    #[Test]
    public function declined_payments_are_not_mapped_as_spend(): void
    {
        $plugin = new MonzoPlugin;
        $method = new ReflectionMethod($plugin, 'setAction');
        foreach (['mastercard', 'payport_faster_payments', 'uk_retail_pot'] as $scheme) {
            $this->assertSame('declined_payment_to', $method->invoke($plugin, [
                'scheme' => $scheme,
                'amount' => -5786,
                'decline_reason' => 'INVALID_EXPIRY_DATE',
            ]));
        }
        $this->assertSame('card_payment_to', $method->invoke($plugin, [
            'scheme' => 'mastercard', 'amount' => -5786, 'decline_reason' => null,
        ]));
    }

    #[Test]
    public function pending_to_settled_replaces_stale_status_tags(): void
    {
        $event = Event::factory()->create(['integration_id' => $this->integration('transactions')->id]);
        $event->attachTag('settled', 'transaction_status');
        $plugin = new MonzoPlugin;
        $method = new ReflectionMethod($plugin, 'tagTransactionEvent');
        $method->invoke($plugin, $event, ['amount' => -100, 'settled' => '', 'amount_is_pending' => true]);
        $this->assertSame(['pending'], $event->fresh()->tags->where('type', 'transaction_status')->pluck('name')->all());
        $method->invoke($plugin, $event, ['amount' => -100, 'settled' => now()->toIso8601String(), 'amount_is_pending' => false]);
        $this->assertSame(['settled'], $event->fresh()->tags->where('type', 'transaction_status')->pluck('name')->all());
        $method->invoke($plugin, $event, ['amount' => -100, 'decline_reason' => 'OTHER']);
        $this->assertSame(['declined'], $event->fresh()->tags->where('type', 'transaction_status')->pluck('name')->all());
    }

    #[Test]
    public function balance_uses_monzo_spend_today_field(): void
    {
        $integration = $this->integration('balances');
        $job = new MonzoBalanceData($integration, [
            '_account' => ['id' => 'acc_test', 'type' => 'uk_retail'],
            'balance' => 10000, 'spend_today' => -1234,
        ], 'acc_test');
        (new ReflectionMethod($job, 'process'))->invoke($job);
        $event = Event::where('integration_id', $integration->id)->where('action', 'had_balance')->firstOrFail();
        $this->assertEquals(-12.34, $event->event_metadata['spent_today']);
        $this->assertSame(1234, $event->blocks()->where('block_type', 'spent_today')->firstOrFail()->value);
    }

    #[Test]
    public function deleted_pots_update_objects_without_new_snapshots(): void
    {
        $integration = $this->integration('pots');
        $job = new MonzoPotData($integration, [
            ['id' => 'pot_deleted', 'name' => 'Old pot', 'deleted' => true, 'balance' => 0],
            ['id' => 'pot_active', 'name' => 'Savings', 'deleted' => false, 'balance' => 1500],
        ], 'acc_test');
        (new ReflectionMethod($job, 'process'))->invoke($job);
        $this->assertSame(1, Event::where('integration_id', $integration->id)->count());
        $this->assertTrue(EventObject::where('user_id', $integration->user_id)->where('metadata->pot_id', 'pot_deleted')->where('type', 'monzo_archived_pot')->exists());
    }

    #[Test]
    public function transactions_page_through_more_than_two_full_pages(): void
    {
        $integration = $this->integration('transactions');
        $transactions = array_map(fn ($i) => ['id' => 'tx_' . $i], range(1, 250));
        Http::preventStrayRequests();
        Http::fake([
            'api.monzo.com/accounts' => Http::response(['accounts' => [['id' => 'acc_test', 'type' => 'uk_retail']]]),
            'api.monzo.com/transactions*' => function ($request) use ($transactions) {
                $since = $request['since'];
                $offset = str_starts_with($since, 'tx_') ? (int) substr($since, 3) : 0;

                return Http::response(['transactions' => array_slice($transactions, $offset, 100)]);
            },
        ]);
        $result = (new MonzoPlugin)->pullTransactionData($integration);
        $this->assertCount(250, $result['acc_test']);
        $this->assertSame('tx_250', $result['acc_test'][249]['id']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/transactions') && $request['since'] === 'tx_100' && ! isset($request['before']));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/transactions') && $request['since'] === 'tx_200');
    }
    #[Test]
    public function failed_page_does_not_mark_sweep_complete(): void
    {
        $integration = $this->integration('transactions');
        $transactions = array_map(fn ($i) => ['id' => 'tx_' . $i], range(1, 100));
        Http::fake([
            'api.monzo.com/accounts' => Http::response(['accounts' => [['id' => 'acc_test', 'type' => 'uk_retail']]]),
            'api.monzo.com/transactions*' => Http::sequence()
                ->push(['transactions' => $transactions])
                ->push(['error' => 'unavailable'], 503),
        ]);
        try {
            (new MonzoPlugin)->pullTransactionData($integration);
            $this->fail('A failed page must fail the pull.');
        } catch (Exception $exception) {
            $this->assertSame('Failed to fetch transaction page from Monzo API', $exception->getMessage());
        }
        $this->assertArrayNotHasKey('monzo_last_sweep_at', $integration->fresh()->configuration ?? []);
    }

    #[Test]
    public function repeated_cursor_fails_without_completing_sweep(): void
    {
        $integration = $this->integration('transactions');
        $transactions = array_map(fn ($i) => ['id' => 'tx_' . $i], range(1, 100));
        Http::fake([
            'api.monzo.com/accounts' => Http::response(['accounts' => [['id' => 'acc_test', 'type' => 'uk_retail']]]),
            'api.monzo.com/transactions*' => Http::response(['transactions' => $transactions]),
        ]);
        try {
            (new MonzoPlugin)->pullTransactionData($integration);
            $this->fail('A repeated cursor must fail the pull.');
        } catch (Exception $exception) {
            $this->assertSame('Monzo transaction pagination did not advance', $exception->getMessage());
        }
        $this->assertArrayNotHasKey('monzo_last_sweep_at', $integration->fresh()->configuration ?? []);
        Http::assertSentCount(3);
    }

}
