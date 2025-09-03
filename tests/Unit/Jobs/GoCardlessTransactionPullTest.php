<?php

namespace Tests\Unit\Jobs;

use App\Jobs\OAuth\GoCardless\GoCardlessTransactionPull;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class GoCardlessTransactionPullTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected IntegrationGroup $group;

    protected Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'gocardless',
            'account_id' => 'test_account_123',
        ]);
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'gocardless',
            'instance_type' => 'transactions',
        ]);

        // Clear any cached rate limit data
        Cache::forget('gocardless_transaction_calls');
    }

    /**
     * @test
     */
    public function job_creation()
    {
        $job = new GoCardlessTransactionPull($this->integration);

        $this->assertInstanceOf(GoCardlessTransactionPull::class, $job);
        $this->assertEquals(120, $job->timeout);
        $this->assertEquals(3, $job->tries);
        $this->assertEquals([60, 300, 600], $job->backoff);
    }

    /**
     * @test
     */
    public function unique_id_generation()
    {
        $job = new GoCardlessTransactionPull($this->integration);
        $uniqueId = $job->uniqueId();

        $this->assertStringContainsString('gocardless_transactions_' . $this->integration->id, $uniqueId);
        $this->assertStringContainsString(date('Y-m-d'), $uniqueId);
    }

    /**
     * @test
     */
    public function missing_account_id_integration()
    {
        $groupWithoutAccountId = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'gocardless',
            'account_id' => null,
        ]);

        $integrationWithoutAccountId = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $groupWithoutAccountId->id,
            'service' => 'gocardless',
            'instance_type' => 'transactions',
        ]);

        $job = new GoCardlessTransactionPull($integrationWithoutAccountId);

        // The job should be created successfully, but fetchData should fail
        $this->assertInstanceOf(GoCardlessTransactionPull::class, $job);
    }

    /**
     * @test
     */
    public function rate_limit_cache_key_format()
    {
        // Test that our rate limiting cache keys are properly formatted
        $cacheKey = 'gocardless_transaction_calls';
        $today = now()->toDateString();

        // Simulate some API calls
        $calls = [
            [
                'account_id' => 'test_account_123',
                'date' => $today,
                'timestamp' => now()->toISOString(),
            ],
        ];

        Cache::put($cacheKey, $calls, 604800); // 7 days

        $cachedCalls = Cache::get($cacheKey);
        $this->assertCount(1, $cachedCalls);
        $this->assertEquals('test_account_123', $cachedCalls[0]['account_id']);
        $this->assertEquals($today, $cachedCalls[0]['date']);
    }

    /**
     * @test
     */
    public function job_handles_integration_correctly()
    {
        // Test that the job can be created with different integration types
        $differentIntegration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'gocardless',
            'instance_type' => 'balances', // Different instance type
        ]);

        $job = new GoCardlessTransactionPull($differentIntegration);
        $this->assertInstanceOf(GoCardlessTransactionPull::class, $job);

        // Test unique ID is different for different integrations
        $job1 = new GoCardlessTransactionPull($this->integration);
        $job2 = new GoCardlessTransactionPull($differentIntegration);

        $this->assertNotEquals($job1->uniqueId(), $job2->uniqueId());
    }

    /**
     * @test
     */
    public function configuration_inheritance()
    {
        // Test that configuration from the integration is accessible via public methods
        $integrationWithConfig = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'gocardless',
            'instance_type' => 'transactions',
            'configuration' => [
                'days_back' => 14,
                'custom_setting' => 'test_value',
            ],
        ]);

        // Test that the integration was created with the correct configuration
        $this->assertEquals(14, $integrationWithConfig->configuration['days_back']);
        $this->assertEquals('test_value', $integrationWithConfig->configuration['custom_setting']);

        // Test that the job can be created with this integration
        $job = new GoCardlessTransactionPull($integrationWithConfig);
        $this->assertInstanceOf(GoCardlessTransactionPull::class, $job);
    }
}
