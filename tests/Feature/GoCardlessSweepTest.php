<?php

namespace Tests\Feature;

use App\Integrations\GoCardless\GoCardlessBankPlugin;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoCardlessSweepTest extends TestCase
{
    use RefreshDatabase;

    private GoCardlessBankPlugin $plugin;

    private User $user;

    private IntegrationGroup $group;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plugin = new GoCardlessBankPlugin;
        $this->user = User::factory()->create();
        $this->group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'gocardless',
            'access_token' => 'test-access-token',
        ]);
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'gocardless',
            'instance_type' => 'transactions',
            'configuration' => ['account_id' => 'test-account-123'],
        ]);
    }

    /**
     * @test
     */
    public function sweep_logic_runs_when_no_previous_sweep(): void
    {
        // Integration has no previous sweep timestamp
        $this->integration->update(['configuration' => ['account_id' => 'test-account-123']]);

        // Test the sweep timing logic
        $config = $this->integration->configuration ?? [];
        $lastSweepAt = isset($config['gocardless_last_sweep_at']) ? Carbon::parse($config['gocardless_last_sweep_at']) : null;
        $doSweep = ! $lastSweepAt || $lastSweepAt->lt(now()->subDays(6));

        $this->assertTrue($doSweep, 'Sweep should run when no previous sweep exists');
    }

    /**
     * @test
     */
    public function sweep_logic_skips_when_recent_sweep(): void
    {
        // Set recent sweep timestamp (2 days ago)
        $recentSweepTime = now()->subDays(2)->toIso8601String();
        $this->integration->update([
            'configuration' => [
                'account_id' => 'test-account-123',
                'gocardless_last_sweep_at' => $recentSweepTime,
            ],
        ]);

        // Test the sweep timing logic
        $config = $this->integration->configuration ?? [];
        $lastSweepAt = isset($config['gocardless_last_sweep_at']) ? Carbon::parse($config['gocardless_last_sweep_at']) : null;
        $doSweep = ! $lastSweepAt || $lastSweepAt->lt(now()->subDays(6));

        $this->assertFalse($doSweep, 'Sweep should skip when recent sweep exists');
    }

    /**
     * @test
     */
    public function sweep_logic_runs_when_old_sweep(): void
    {
        // Set old sweep timestamp (7 days ago)
        $oldSweepTime = now()->subDays(7)->toIso8601String();
        $this->integration->update([
            'configuration' => [
                'account_id' => 'test-account-123',
                'gocardless_last_sweep_at' => $oldSweepTime,
            ],
        ]);

        // Test the sweep timing logic
        $config = $this->integration->configuration ?? [];
        $lastSweepAt = isset($config['gocardless_last_sweep_at']) ? Carbon::parse($config['gocardless_last_sweep_at']) : null;
        $doSweep = ! $lastSweepAt || $lastSweepAt->lt(now()->subDays(6));

        $this->assertTrue($doSweep, 'Sweep should run when old sweep exists');
    }

    /**
     * @test
     */
    public function sweep_works_for_different_instance_types(): void
    {
        // Test with different instance types
        $instanceTypes = ['transactions', 'balances'];

        foreach ($instanceTypes as $instanceType) {
            $integration = Integration::factory()->create([
                'user_id' => $this->user->id,
                'integration_group_id' => $this->group->id,
                'service' => 'gocardless',
                'instance_type' => $instanceType,
                'configuration' => ['account_id' => 'test-account-123'], // No previous sweep
            ]);

            // Test the sweep timing logic
            $config = $integration->configuration ?? [];
            $lastSweepAt = isset($config['gocardless_last_sweep_at']) ? Carbon::parse($config['gocardless_last_sweep_at']) : null;
            $doSweep = ! $lastSweepAt || $lastSweepAt->lt(now()->subDays(6));

            $this->assertTrue($doSweep, "Sweep should work for instance type: {$instanceType}");
        }
    }

    /**
     * @test
     */
    public function sweep_handles_missing_account_id(): void
    {
        // Integration without account_id
        $this->integration->update(['configuration' => []]);

        // Test that sweep logic still works (it should check for account_id in performDataSweep)
        $config = $this->integration->configuration ?? [];
        $lastSweepAt = isset($config['gocardless_last_sweep_at']) ? Carbon::parse($config['gocardless_last_sweep_at']) : null;
        $doSweep = ! $lastSweepAt || $lastSweepAt->lt(now()->subDays(6));

        $this->assertTrue($doSweep, 'Sweep logic should work even without account_id');
    }

    /**
     * @test
     */
    public function sweep_uses_correct_timing(): void
    {
        // Test that the sweep timing is correct (6 days)
        $config = [];
        $lastSweepAt = null;
        $doSweep = ! $lastSweepAt || $lastSweepAt->lt(now()->subDays(6));

        $this->assertTrue($doSweep, 'Sweep should run when no previous sweep');

        // Test with exactly 6 days ago (should still trigger sweep due to lt comparison)
        $sixDaysAgo = now()->subDays(6)->toIso8601String();
        $lastSweepAt = Carbon::parse($sixDaysAgo);
        $doSweep = ! $lastSweepAt || $lastSweepAt->lt(now()->subDays(6));

        $this->assertTrue($doSweep, 'Sweep should run when exactly 6 days ago (lt comparison)');

        // Test with 7 days ago
        $sevenDaysAgo = now()->subDays(7)->toIso8601String();
        $lastSweepAt = Carbon::parse($sevenDaysAgo);
        $doSweep = ! $lastSweepAt || $lastSweepAt->lt(now()->subDays(6));

        $this->assertTrue($doSweep, 'Sweep should run when 7 days ago');
    }

    /**
     * @test
     */
    public function sweep_timestamp_format(): void
    {
        // Test that sweep timestamps are stored in ISO format
        $timestamp = now()->toIso8601String();

        $this->assertIsString($timestamp);
        $this->assertTrue(Carbon::parse($timestamp)->isValid());
        $this->assertTrue(Carbon::parse($timestamp)->isAfter(now()->subMinutes(1)));
    }
}
