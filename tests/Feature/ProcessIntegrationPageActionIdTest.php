<?php

namespace Tests\Feature;

use App\Jobs\Migrations\ProcessIntegrationPage;
use App\Models\ActionProgress;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class ProcessIntegrationPageActionIdTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function generate_action_id_creates_unique_ids_for_different_job_types(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'monzo',
            'instance_type' => 'transactions',
        ]);

        // Test pots job
        $potsJob = new ProcessIntegrationPage(
            $integration,
            [['kind' => 'pots_snapshot']],
            ['service' => 'monzo', 'instance_type' => 'pots']
        );

        // Test balances job
        $balancesJob = new ProcessIntegrationPage(
            $integration,
            [['kind' => 'balance_snapshot', 'date' => '2025-01-01']],
            ['service' => 'monzo', 'instance_type' => 'balances']
        );

        // Test transactions job with specific window
        $transactionsJob = new ProcessIntegrationPage(
            $integration,
            [['kind' => 'transactions_window', 'since' => '2025-01-01T00:00:00Z', 'before' => '2025-01-02T00:00:00Z']],
            ['service' => 'monzo', 'instance_type' => 'transactions']
        );

        // Use reflection to access the private generateActionId method
        $reflection = new ReflectionMethod(ProcessIntegrationPage::class, 'generateActionId');
        $reflection->setAccessible(true);

        $potsActionId = $reflection->invoke($potsJob);
        $balancesActionId = $reflection->invoke($balancesJob);
        $transactionsActionId = $reflection->invoke($transactionsJob);

        // Assert unique IDs are generated
        $this->assertStringEndsWith('_pots', $potsActionId);
        $this->assertStringEndsWith('_balances', $balancesActionId);
        $this->assertStringContainsString('_transactions_', $transactionsActionId);

        // Assert they're all different
        $this->assertNotEquals($potsActionId, $balancesActionId);
        $this->assertNotEquals($potsActionId, $transactionsActionId);
        $this->assertNotEquals($balancesActionId, $transactionsActionId);

        // Assert they all start with the same integration prefix
        $expectedPrefix = "integration_{$integration->id}";
        $this->assertStringStartsWith($expectedPrefix, $potsActionId);
        $this->assertStringStartsWith($expectedPrefix, $balancesActionId);
        $this->assertStringStartsWith($expectedPrefix, $transactionsActionId);
    }

    /**
     * @test
     */
    public function different_transaction_windows_generate_different_action_ids(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'monzo',
            'instance_type' => 'transactions',
        ]);

        // Test two different transaction windows
        $window1Job = new ProcessIntegrationPage(
            $integration,
            [['kind' => 'transactions_window', 'since' => '2025-01-01T00:00:00Z', 'before' => '2025-01-02T00:00:00Z']],
            ['service' => 'monzo', 'instance_type' => 'transactions']
        );

        $window2Job = new ProcessIntegrationPage(
            $integration,
            [['kind' => 'transactions_window', 'since' => '2025-01-02T00:00:00Z', 'before' => '2025-01-03T00:00:00Z']],
            ['service' => 'monzo', 'instance_type' => 'transactions']
        );

        // Use reflection to access the private generateActionId method
        $reflection = new ReflectionMethod(ProcessIntegrationPage::class, 'generateActionId');
        $reflection->setAccessible(true);

        $window1ActionId = $reflection->invoke($window1Job);
        $window2ActionId = $reflection->invoke($window2Job);

        // Assert different windows generate different IDs
        $this->assertNotEquals($window1ActionId, $window2ActionId);
        $this->assertStringContainsString('_transactions_', $window1ActionId);
        $this->assertStringContainsString('_transactions_', $window2ActionId);

        // Both should start with integration prefix
        $expectedPrefix = "integration_{$integration->id}";
        $this->assertStringStartsWith($expectedPrefix, $window1ActionId);
        $this->assertStringStartsWith($expectedPrefix, $window2ActionId);
    }

    /**
     * @test
     */
    public function processing_jobs_create_separate_progress_records(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'monzo',
            'instance_type' => 'transactions',
        ]);

        // Simulate two jobs running (like they would in a real migration)
        $potsJob = new ProcessIntegrationPage(
            $integration,
            [['kind' => 'pots_snapshot']],
            ['service' => 'monzo', 'instance_type' => 'pots']
        );

        $balancesJob = new ProcessIntegrationPage(
            $integration,
            [['kind' => 'balance_snapshot', 'date' => '2025-01-01']],
            ['service' => 'monzo', 'instance_type' => 'balances']
        );

        // Use reflection to call generateActionId and simulate progress creation
        $reflection = new ReflectionMethod(ProcessIntegrationPage::class, 'generateActionId');
        $reflection->setAccessible(true);

        $potsActionId = $reflection->invoke($potsJob);
        $balancesActionId = $reflection->invoke($balancesJob);

        // Create progress records like the jobs would
        $potsProgress = ActionProgress::createProgress(
            $user->id,
            'migration',
            $potsActionId,
            'processing_pots',
            'Processing pots data...',
            70
        );

        $balancesProgress = ActionProgress::createProgress(
            $user->id,
            'migration',
            $balancesActionId,
            'processing_balances',
            'Processing balances data...',
            70
        );

        // Assert both progress records were created with different action IDs
        $this->assertNotNull($potsProgress);
        $this->assertNotNull($balancesProgress);
        $this->assertNotEquals($potsProgress->action_id, $balancesProgress->action_id);
        $this->assertEquals($potsActionId, $potsProgress->action_id);
        $this->assertEquals($balancesActionId, $balancesProgress->action_id);

        // Verify we can retrieve them independently
        $retrievedPotsProgress = ActionProgress::getLatestProgress($user->id, 'migration', $potsActionId);
        $retrievedBalancesProgress = ActionProgress::getLatestProgress($user->id, 'migration', $balancesActionId);

        $this->assertNotNull($retrievedPotsProgress);
        $this->assertNotNull($retrievedBalancesProgress);
        $this->assertEquals('processing_pots', $retrievedPotsProgress->step);
        $this->assertEquals('processing_balances', $retrievedBalancesProgress->step);
    }
}
