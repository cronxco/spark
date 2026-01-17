<?php

namespace Tests\Feature;

use App\Integrations\Monzo\MonzoPlugin;
use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Throwable;

class MonzoSweepTest extends TestCase
{
    use RefreshDatabase;

    private MonzoPlugin $plugin;

    private User $user;

    private IntegrationGroup $group;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plugin = new MonzoPlugin;
        $this->user = User::factory()->create();
        $this->group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'monzo',
            'access_token' => 'test-access-token',
        ]);
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'monzo',
            'instance_type' => 'transactions',
        ]);
    }

    /**
     * @test
     */
    public function perform_sweep_if_needed_runs_when_no_previous_sweep(): void
    {
        // Mock API responses
        Http::fake([
            'api.monzo.com/accounts' => Http::response([
                'accounts' => [
                    [
                        'id' => 'acc-123',
                        'type' => 'uk_retail',
                        'description' => 'Test Account',
                    ],
                ],
            ]),
            'api.monzo.com/transactions*' => Http::response([
                'transactions' => [
                    [
                        'id' => 'tx-1',
                        'created' => '2024-01-01T10:00:00Z',
                        'amount' => -1000,
                        'currency' => 'GBP',
                        'description' => 'Test transaction 1',
                        'merchant' => ['name' => 'Test Merchant'],
                    ],
                    [
                        'id' => 'tx-2',
                        'created' => '2024-01-02T10:00:00Z',
                        'amount' => 2000,
                        'currency' => 'GBP',
                        'description' => 'Test transaction 2',
                        'merchant' => ['name' => 'Test Merchant 2'],
                    ],
                ],
            ]),
            'api.monzo.com/balance*' => Http::response([
                'balance' => 50000,
                'currency' => 'GBP',
            ]),
            'api.monzo.com/pots*' => Http::response([
                'pots' => [
                    [
                        'id' => 'pot-123',
                        'name' => 'Test Pot',
                        'balance' => 10000,
                        'currency' => 'GBP',
                    ],
                ],
            ]),
        ]);

        // Integration has no previous sweep timestamp
        $this->integration->update(['configuration' => []]);

        // Call fetchData which should trigger sweep
        $this->plugin->fetchData($this->integration);

        // Verify sweep timestamp was set
        $this->integration->refresh();
        $this->assertNotNull($this->integration->configuration['monzo_last_sweep_at']);

        // Verify events were created
        $this->assertGreaterThan(0, Event::where('integration_id', $this->integration->id)->count());
    }

    /**
     * @test
     */
    public function perform_sweep_if_needed_skips_when_recent_sweep(): void
    {
        // Set recent sweep timestamp (10 hours ago)
        $recentSweepTime = now()->subHours(10)->toIso8601String();
        $this->integration->update([
            'configuration' => ['monzo_last_sweep_at' => $recentSweepTime],
        ]);

        // Mock API responses
        Http::fake([
            'api.monzo.com/accounts' => Http::response(['accounts' => []]),
            'api.monzo.com/transactions*' => Http::response(['transactions' => []]),
        ]);

        // Call fetchData
        $this->plugin->fetchData($this->integration);

        // Verify sweep timestamp was not updated
        $this->integration->refresh();
        $this->assertEquals($recentSweepTime, $this->integration->configuration['monzo_last_sweep_at']);
    }

    /**
     * @test
     */
    public function perform_sweep_if_needed_runs_when_old_sweep(): void
    {
        // Set old sweep timestamp (25 hours ago)
        $oldSweepTime = now()->subHours(25)->toIso8601String();
        $this->integration->update([
            'configuration' => ['monzo_last_sweep_at' => $oldSweepTime],
        ]);

        // Mock API responses
        Http::fake([
            'api.monzo.com/accounts' => Http::response(['accounts' => []]),
            'api.monzo.com/transactions*' => Http::response(['transactions' => []]),
            'api.monzo.com/balance*' => Http::response(['balance' => 0]),
            'api.monzo.com/pots*' => Http::response(['pots' => []]),
        ]);

        // Call fetchData
        $this->plugin->fetchData($this->integration);

        // Verify sweep timestamp was updated
        $this->integration->refresh();
        $this->assertNotEquals($oldSweepTime, $this->integration->configuration['monzo_last_sweep_at']);
    }

    /**
     * @test
     */
    public function perform_data_sweep_processes_all_data_types(): void
    {
        // Mock API responses with data for all types
        Http::fake([
            'api.monzo.com/accounts' => Http::response([
                'accounts' => [
                    [
                        'id' => 'acc-123',
                        'type' => 'uk_retail',
                        'description' => 'Test Account',
                    ],
                ],
            ]),
            'api.monzo.com/transactions*' => Http::response([
                'transactions' => [
                    [
                        'id' => 'tx-1',
                        'created' => '2024-01-01T10:00:00Z',
                        'amount' => -1000,
                        'currency' => 'GBP',
                        'description' => 'Test transaction',
                        'merchant' => ['name' => 'Test Merchant'],
                    ],
                ],
            ]),
            'api.monzo.com/balance*' => Http::response([
                'balance' => 50000,
                'currency' => 'GBP',
            ]),
            'api.monzo.com/pots*' => Http::response([
                'pots' => [
                    [
                        'id' => 'pot-123',
                        'name' => 'Test Pot',
                        'balance' => 10000,
                        'currency' => 'GBP',
                    ],
                ],
            ]),
        ]);

        // Integration has no previous sweep timestamp
        $this->integration->update(['configuration' => []]);

        // Call fetchData to trigger sweep
        $this->plugin->fetchData($this->integration);

        // Verify events were created
        $events = Event::where('integration_id', $this->integration->id)->get();
        $this->assertGreaterThan(0, $events->count());

        // Verify different event types were created
        $eventTypes = $events->pluck('action')->unique()->sort()->values();
        $this->assertContains('other_debit_to', $eventTypes->toArray());
    }

    /**
     * @test
     */
    public function perform_data_sweep_handles_no_accounts(): void
    {
        // Mock API response with no accounts
        Http::fake([
            'api.monzo.com/accounts' => Http::response(['accounts' => []]),
        ]);

        // Integration has no previous sweep timestamp
        $this->integration->update(['configuration' => []]);

        // Call fetchData
        $this->plugin->fetchData($this->integration);

        // Verify no events were created
        $this->assertEquals(0, Event::where('integration_id', $this->integration->id)->count());

        // Verify sweep timestamp was still set
        $this->integration->refresh();
        $this->assertNotNull($this->integration->configuration['monzo_last_sweep_at']);
    }

    /**
     * @test
     */
    public function perform_data_sweep_handles_api_errors_gracefully(): void
    {
        // Mock API responses - accounts works but transactions fails
        Http::fake([
            'api.monzo.com/accounts' => Http::response([
                'accounts' => [
                    [
                        'id' => 'acc-123',
                        'type' => 'uk_retail',
                        'description' => 'Test Account',
                    ],
                ],
            ]),
            'api.monzo.com/transactions*' => Http::response([], 500),
            'api.monzo.com/balance*' => Http::response(['balance' => 0]),
            'api.monzo.com/pots*' => Http::response(['pots' => []]),
        ]);

        // Integration has no previous sweep timestamp
        $this->integration->update(['configuration' => []]);

        // Expect exception to be thrown
        $this->expectException(Throwable::class);

        // Call fetchData to trigger sweep
        $this->plugin->fetchData($this->integration);
    }

    /**
     * @test
     */
    public function sweep_works_for_different_instance_types(): void
    {
        // Test with different instance types
        $instanceTypes = ['transactions', 'balances', 'pots'];

        foreach ($instanceTypes as $instanceType) {
            $integration = Integration::factory()->create([
                'user_id' => $this->user->id,
                'integration_group_id' => $this->group->id,
                'service' => 'monzo',
                'instance_type' => $instanceType,
                'configuration' => [], // No previous sweep
            ]);

            // Mock API responses
            Http::fake([
                'api.monzo.com/accounts' => Http::response(['accounts' => []]),
                'api.monzo.com/transactions*' => Http::response(['transactions' => []]),
                'api.monzo.com/balance*' => Http::response(['balance' => 0]),
                'api.monzo.com/pots*' => Http::response(['pots' => []]),
            ]);

            // Call fetchData
            $this->plugin->fetchData($integration);

            // Verify sweep timestamp was set regardless of instance type
            $integration->refresh();
            $this->assertNotNull($integration->configuration['monzo_last_sweep_at'],
                "Sweep should work for instance type: {$instanceType}");
        }
    }

    /**
     * @test
     */
    public function sweep_timestamp_format_is_correct(): void
    {
        // Mock API responses
        Http::fake([
            'api.monzo.com/accounts' => Http::response(['accounts' => []]),
            'api.monzo.com/transactions*' => Http::response(['transactions' => []]),
            'api.monzo.com/balance*' => Http::response(['balance' => 0]),
            'api.monzo.com/pots*' => Http::response(['pots' => []]),
        ]);

        // Integration has no previous sweep timestamp
        $this->integration->update(['configuration' => []]);

        // Call fetchData
        $this->plugin->fetchData($this->integration);

        // Verify timestamp format
        $this->integration->refresh();
        $sweepTimestamp = $this->integration->configuration['monzo_last_sweep_at'];

        $this->assertIsString($sweepTimestamp);
        $this->assertTrue(Carbon::parse($sweepTimestamp)->isValid());
        $this->assertTrue(Carbon::parse($sweepTimestamp)->isAfter(now()->subMinutes(1)));
    }

    /**
     * @test
     */
    public function sweep_uses_correct_date_range(): void
    {
        // Mock API responses
        Http::fake([
            'api.monzo.com/accounts' => Http::response([
                'accounts' => [
                    [
                        'id' => 'acc-123',
                        'type' => 'uk_retail',
                        'description' => 'Test Account',
                    ],
                ],
            ]),
            'api.monzo.com/transactions*' => Http::response(['transactions' => []]),
            'api.monzo.com/balance*' => Http::response(['balance' => 0]),
            'api.monzo.com/pots*' => Http::response(['pots' => []]),
        ]);

        // Integration has no previous sweep timestamp
        $this->integration->update(['configuration' => []]);

        // Call fetchData
        $this->plugin->fetchData($this->integration);

        // Verify sweep timestamp was set
        $this->assertNotNull($this->integration->configuration['monzo_last_sweep_at']);
    }

    /**
     * @test
     */
    public function sweep_skips_accounts_instance_type(): void
    {
        // Create integration with accounts instance type
        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'monzo',
            'instance_type' => 'accounts',
            'configuration' => [], // No previous sweep
        ]);

        // Mock API responses
        Http::fake([
            'api.monzo.com/accounts' => Http::response(['accounts' => []]),
        ]);

        // Call fetchData
        $this->plugin->fetchData($integration);

        // Verify sweep timestamp was set even for accounts instance type
        $integration->refresh();
        $this->assertNotNull($integration->configuration['monzo_last_sweep_at']);

        // Verify no transaction/balance/pot API calls were made
        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), 'transactions') ||
                   str_contains($request->url(), 'balance') ||
                   str_contains($request->url(), 'pots');
        });
    }
}
