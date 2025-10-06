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

class MonzoMigrationProgressTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function monzo_migration_processing_creates_and_completes_progress_record(): void
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
                        'created' => '2024-01-01T12:00:00Z',
                        'category' => 'groceries',
                        'description' => 'Test Transaction',
                        'scheme' => 'mastercard',
                    ],
                ],
            ], 200),
        ]);

        // No initial progress record should exist
        $initialProgress = ActionProgress::getLatestProgress(
            $integration->user_id,
            'migration',
            "integration_{$integration->id}"
        );
        $this->assertNull($initialProgress);

        // Execute the job
        $since = now()->subDays(1)->startOfDay()->toIso8601String();
        $before = now()->toIso8601String();
        $items = [['kind' => 'transactions_window', 'since' => $since, 'before' => $before]];
        $context = ['service' => 'monzo', 'instance_type' => 'transactions'];

        $job = new ProcessIntegrationPage($integration, $items, $context);
        $job->handle();

        // Check that a progress record was created and completed
        $finalProgress = ActionProgress::getLatestProgress(
            $integration->user_id,
            'migration',
            "integration_{$integration->id}"
        );

        $this->assertNotNull($finalProgress);
        $this->assertEquals('completed', $finalProgress->step);
        $this->assertEquals(100, $finalProgress->progress);
        $this->assertTrue($finalProgress->isCompleted());
        $this->assertEquals('monzo', $finalProgress->details['service']);
        $this->assertEquals('transactions', $finalProgress->details['instance_type']);
    }

    #[Test]
    public function monzo_pots_migration_processing_creates_and_completes_progress_record(): void
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
        $context = ['service' => 'monzo', 'instance_type' => 'pots'];

        $job = new ProcessIntegrationPage($integration, $items, $context);
        $job->handle();

        // Check that progress was completed
        $progress = ActionProgress::getLatestProgress(
            $integration->user_id,
            'migration',
            "integration_{$integration->id}"
        );

        $this->assertNotNull($progress);
        $this->assertEquals('completed', $progress->step);
        $this->assertEquals(100, $progress->progress);
        $this->assertTrue($progress->isCompleted());
        $this->assertEquals('monzo', $progress->details['service']);
        $this->assertEquals('pots', $progress->details['instance_type']);
    }

    #[Test]
    public function monzo_balances_migration_processing_creates_and_completes_progress_record(): void
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

        $items = [['kind' => 'balance_snapshot', 'date' => '2024-01-02']];
        $context = ['service' => 'monzo', 'instance_type' => 'balances'];

        $job = new ProcessIntegrationPage($integration, $items, $context);
        $job->handle();

        // Check that progress was completed
        $progress = ActionProgress::getLatestProgress(
            $integration->user_id,
            'migration',
            "integration_{$integration->id}"
        );

        $this->assertNotNull($progress);
        $this->assertEquals('completed', $progress->step);
        $this->assertEquals(100, $progress->progress);
        $this->assertTrue($progress->isCompleted());
        $this->assertEquals('monzo', $progress->details['service']);
        $this->assertEquals('balances', $progress->details['instance_type']);
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
