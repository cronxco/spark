<?php

namespace Tests\Feature;

use App\Jobs\Migrations\ProcessIntegrationPage;
use App\Models\ActionProgress;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessIntegrationPageUndefinedVariableTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function process_integration_page_handles_non_transactions_instance_type_without_undefined_variable_error(): void
    {
        // Create a Monzo integration with 'pots' instance type (not 'transactions')
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

        // Create items that would trigger the transactions processing path
        $items = [['kind' => 'pots_snapshot']];
        $context = ['service' => 'monzo', 'instance_type' => 'pots', 'processing_phase' => true];

        $job = new ProcessIntegrationPage($integration, $items, $context);

        // This should not throw an "Undefined variable $windows" error
        $job->handle();

        // Verify that a progress record was created and completed
        $progress = ActionProgress::getLatestProgress(
            $integration->user_id,
            'migration',
            "integration_{$integration->id}_pots"
        );

        $this->assertNotNull($progress);
        $this->assertEquals('completed', $progress->step);
        $this->assertEquals(100, $progress->progress);
        $this->assertEquals('monzo', $progress->details['service']);
        $this->assertEquals('pots', $progress->details['instance_type']);
    }

    #[Test]
    public function process_integration_page_handles_balances_instance_type_without_undefined_variable_error(): void
    {
        // Create a Monzo integration with 'balances' instance type
        $integration = $this->makeMonzoIntegration('balances');

        Http::fake([
            'api.monzo.com/accounts*' => Http::response([
                'accounts' => [
                    ['id' => 'acc_1', 'type' => 'uk_retail', 'created' => '2024-01-01T00:00:00Z'],
                ],
            ], 200),
            'api.monzo.com/balance*' => Http::response([
                'balance' => 1000,
                'spent_today' => -200,
            ], 200),
        ]);

        // Create items that would trigger the balances processing path
        $items = [['kind' => 'balance_snapshot', 'date' => '2024-01-02']];
        $context = ['service' => 'monzo', 'instance_type' => 'balances', 'processing_phase' => true];

        $job = new ProcessIntegrationPage($integration, $items, $context);

        // This should not throw an "Undefined variable $windows" error
        $job->handle();

        // Verify that a progress record was created and completed
        $progress = ActionProgress::getLatestProgress(
            $integration->user_id,
            'migration',
            "integration_{$integration->id}_balances"
        );

        $this->assertNotNull($progress);
        $this->assertEquals('completed', $progress->step);
        $this->assertEquals(100, $progress->progress);
        $this->assertEquals('monzo', $progress->details['service']);
        $this->assertEquals('balances', $progress->details['instance_type']);
    }

    #[Test]
    public function process_integration_page_still_works_for_transactions_instance_type(): void
    {
        // Create a Monzo integration with 'transactions' instance type
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
                        'created' => '2024-01-01T12:00:00Z',
                        'category' => 'groceries',
                        'description' => 'Test Transaction',
                        'scheme' => 'mastercard',
                    ],
                ],
            ], 200),
        ]);

        // Create items with a transactions window
        $items = [[
            'kind' => 'transactions_window',
            'since' => '2024-01-01T00:00:00Z',
            'before' => '2024-01-02T00:00:00Z',
        ]];
        $context = ['service' => 'monzo', 'instance_type' => 'transactions', 'processing_phase' => true];

        $job = new ProcessIntegrationPage($integration, $items, $context);

        // This should work as before
        $job->handle();

        // Verify that a progress record was created and completed
        $since = '2024-01-01T00:00:00Z';
        $before = '2024-01-02T00:00:00Z';
        $progress = ActionProgress::getLatestProgress(
            $integration->user_id,
            'migration',
            "integration_{$integration->id}_transactions_".substr(md5($since.$before), 0, 8)
        );

        $this->assertNotNull($progress);
        $this->assertEquals('completed', $progress->step);
        $this->assertEquals(100, $progress->progress);
        $this->assertEquals('monzo', $progress->details['service']);
        $this->assertEquals('transactions', $progress->details['instance_type']);
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
            'name' => 'Monzo '.ucfirst($instanceType),
            'instance_type' => $instanceType,
            'configuration' => [],
        ]);
    }
}
