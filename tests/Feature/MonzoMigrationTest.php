<?php

namespace Tests\Feature;

use App\Jobs\Migrations\ProcessIntegrationPage;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MonzoMigrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function migration_processes_transactions_window_creates_events(): void
    {
        $integration = $this->makeMonzoIntegration('transactions');

        Http::fake([
            'api.monzo.com/accounts*' => Http::response([
                'accounts' => [
                    ['id' => 'acc_1', 'type' => 'uk_retail', 'created' => '2024-01-01T00:00:00Z'],
                ],
            ], 200),
            'api.monzo.com/transactions*' => Http::response([
                'transactions' => [
                    [
                        'id' => 'tx_1',
                        'amount' => -500,
                        'currency' => 'GBP',
                        'local_amount' => -550,
                        'local_currency' => 'EUR',
                        'created' => '2024-01-01T12:00:00Z',
                        'category' => 'groceries',
                        'description' => 'Test Transaction',
                        'scheme' => 'mastercard',
                        'merchant' => [
                            'id' => 'merch_1',
                            'name' => 'Test Store',
                            'category' => 'eating_out',
                            'logo' => 'https://example.com/logo.png',
                            'address' => [
                                'address' => '123 High St',
                                'city' => 'London',
                                'postcode' => 'N1 3JD',
                                'country' => 'GB',
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $since = now()->subDays(1)->startOfDay()->toIso8601String();
        $before = now()->toIso8601String();
        $items = [[
            'kind' => 'transactions_window',
            'since' => $since,
            'before' => $before,
        ]];
        $context = [
            'service' => 'monzo',
            'instance_type' => 'transactions',
        ];

        $job = new ProcessIntegrationPage($integration, $items, $context);
        $job->handle();

        $this->assertDatabaseHas('events', [
            'integration_id' => $integration->id,
            'source_id' => 'tx_1',
            'service' => 'monzo',
            'action' => 'card_payment_to',
        ]);

        $event = Event::with('blocks')->where('integration_id', $integration->id)->where('source_id', 'tx_1')->first();
        $this->assertNotNull($event);
        $this->assertEquals(500, $event->value);
        $this->assertEquals(100, $event->value_multiplier);

        // Stored under master 'accounts' integration
        $target = EventObject::where('user_id', $integration->user_id)
            ->where('type', 'monzo_counterparty')
            ->where('title', 'Test Store')
            ->first();
        $this->assertNotNull($target);

        // Blocks: Merchant and FX
        $this->assertTrue($event->blocks->contains(function ($b) {
            $metadata = is_array($b->metadata ?? null) ? $b->metadata : [];

            return $b->title === 'Merchant' && isset($metadata['merchant']) && str_contains($metadata['merchant'], 'Test Store');
        }));
        $this->assertTrue($event->blocks->contains(function ($b) {
            $metadata = is_array($b->metadata ?? null) ? $b->metadata : [];

            return $b->title === 'FX' && isset($metadata['EUR']) && isset($metadata['GBP']);
        }));
    }

    #[Test]
    public function migration_pots_snapshot_creates_pot_objects(): void
    {
        $integration = $this->makeMonzoIntegration('pots');

        Http::fake([
            'api.monzo.com/accounts*' => Http::response([
                'accounts' => [
                    ['id' => 'acc_1', 'type' => 'uk_retail', 'created' => '2024-01-01T00:00:00Z'],
                ],
            ], 200),
            'api.monzo.com/pots*' => Http::response([
                'pots' => [
                    ['id' => 'pot_1', 'name' => 'Rainy Day', 'balance' => 1234, 'created' => '2024-01-01T00:00:00Z', 'deleted' => false],
                ],
            ], 200),
        ]);

        $items = [['kind' => 'pots_snapshot']];
        $context = [
            'service' => 'monzo',
            'instance_type' => 'pots',
        ];

        $job = new ProcessIntegrationPage($integration, $items, $context);
        $job->handle();

        $master = Integration::where('integration_group_id', $integration->integration_group_id)
            ->where('service', 'monzo')
            ->where('instance_type', 'accounts')
            ->first();
        $this->assertNotNull($master);
        $this->assertDatabaseHas('objects', [
            'user_id' => $integration->user_id,
            'type' => 'monzo_pot',
            'title' => 'Rainy Day',
        ]);
    }

    #[Test]
    public function migration_balance_snapshot_creates_daily_event(): void
    {
        $integration = $this->makeMonzoIntegration('balances');

        Http::fake([
            'api.monzo.com/accounts*' => Http::response([
                'accounts' => [
                    ['id' => 'acc_1', 'type' => 'uk_retail', 'created' => '2024-01-01T00:00:00Z'],
                ],
            ], 200),
            'api.monzo.com/balance*' => Http::response([
                'balance' => 1000,
                'spend_today' => -200,
            ], 200),
        ]);

        $items = [[
            'kind' => 'balance_snapshot',
            'date' => '2024-01-02',
        ]];
        $context = [
            'service' => 'monzo',
            'instance_type' => 'balances',
        ];

        $job = new ProcessIntegrationPage($integration, $items, $context);
        $job->handle();

        $this->assertDatabaseHas('events', [
            'integration_id' => $integration->id,
            'source_id' => 'monzo_balance_acc_1_2024-01-02',
            'service' => 'monzo',
            'action' => 'had_balance',
        ]);

        $event = Event::with('blocks')->where('integration_id', $integration->id)->where('source_id', 'monzo_balance_acc_1_2024-01-02')->first();
        $this->assertNotNull($event);
        $this->assertEquals(1000, $event->value);
        $this->assertEquals(100, $event->value_multiplier);
        $this->assertEquals(-2.0, $event->event_metadata['spend_today']);

        // Blocks: Spend Today exists with value 200 cents
        $this->assertTrue($event->blocks->contains(function ($b) {
            return $b->title === 'Spend Today' && (int) $b->value === 200 && (int) $b->value_multiplier === 100 && $b->value_unit === 'GBP';
        }));
    }

    private function makeMonzoIntegration(string $instanceType = 'transactions'): Integration
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => 'monzo',
            'account_id' => null,
            'access_token' => 'test-token',
            'refresh_token' => null,
        ]);

        return Integration::create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'monzo',
            'name' => 'Monzo ' . ucfirst($instanceType),
            'instance_type' => $instanceType,
            'configuration' => [],
        ]);
    }
}
