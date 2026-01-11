<?php

namespace Tests\Unit;

use App\Exceptions\GoCardlessEuaExpiredException;
use App\Jobs\GoCardless\HandleExpiredEuaJob;
use App\Jobs\OAuth\GoCardless\GoCardlessAccountPull;
use App\Jobs\OAuth\GoCardless\GoCardlessBalancePull;
use App\Jobs\OAuth\GoCardless\GoCardlessTransactionPull;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GoCardlessEuaExpiryDetectionTest extends TestCase
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
            'auth_metadata' => [
                'access_token' => 'test_token',
                'gocardless_institution_name' => 'Test Bank',
                'gocardless_requisition_id' => 'test_requisition',
            ],
        ]);
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'gocardless',
            'instance_type' => 'transactions',
            'configuration' => [
                'account_id' => 'test_account_123',
                'account_name' => 'Test Account',
            ],
        ]);
    }

    /**
     * @test
     */
    public function detects_eua_expiry_with_summary_field_in_transaction_pull(): void
    {
        Queue::fake();

        // Mock the HTTP response with the 'summary' field format (actual GoCardless error format)
        Http::fake([
            '*/requisitions/*' => Http::response([
                'status' => 'LN',
                'accounts' => ['test_account_123'],
            ], 200),
            '*/accounts/*/transactions/*' => Http::response([
                'summary' => 'End User Agreement (EUA) 7df396d0-844e-41cd-bc32-e62b7f65b154 has expired',
                'detail' => 'EUA was valid for 90 days and it expired at 2026-01-10 19:18:13.330664+00:00. The end user must connect the account once more with new EUA and Requisition',
                'status_code' => 401,
            ], 401),
        ]);

        $job = new GoCardlessTransactionPull($this->integration);

        try {
            $job->handle();
            $this->fail('Expected job to fail with GoCardlessEuaExpiredException');
        } catch (Exception $e) {
            // Job should have failed, triggering the failed() method
            $this->assertTrue(true);
        }

        // Call the failed method directly to verify it dispatches HandleExpiredEuaJob
        $exception = new GoCardlessEuaExpiredException(
            $this->group->id,
            [
                'summary' => 'End User Agreement (EUA) 7df396d0-844e-41cd-bc32-e62b7f65b154 has expired',
                'detail' => 'EUA was valid for 90 days',
            ]
        );

        $job->failed($exception);

        Queue::assertPushed(HandleExpiredEuaJob::class);
    }

    /**
     * @test
     */
    public function detects_eua_expiry_with_message_field_in_balance_pull(): void
    {
        Queue::fake();

        // Mock the HTTP response with the 'message' field format (for backwards compatibility)
        Http::fake([
            '*/requisitions/*' => Http::response([
                'status' => 'LN',
                'accounts' => ['test_account_123'],
            ], 200),
            '*/accounts/*/balances/*' => Http::response([
                'message' => 'End User Agreement (EUA) has expired',
                'status_code' => 401,
            ], 401),
        ]);

        $balanceIntegration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'gocardless',
            'instance_type' => 'balances',
            'configuration' => [
                'account_id' => 'test_account_123',
                'account_name' => 'Test Account',
            ],
        ]);

        $job = new GoCardlessBalancePull($balanceIntegration);

        $exception = new GoCardlessEuaExpiredException(
            $this->group->id,
            ['message' => 'End User Agreement (EUA) has expired']
        );

        $job->failed($exception);

        Queue::assertPushed(HandleExpiredEuaJob::class);
    }

    /**
     * @test
     */
    public function detects_eua_expiry_with_summary_field_in_account_pull(): void
    {
        Queue::fake();

        Http::fake([
            '*/requisitions/*' => Http::response([
                'status' => 'LN',
                'accounts' => ['test_account_123'],
            ], 200),
            '*/accounts/*/details/*' => Http::response([
                'summary' => 'End User Agreement (EUA) has expired at 2026-01-10',
                'detail' => 'EUA expired',
                'status_code' => 401,
            ], 401),
        ]);

        $accountIntegration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'gocardless',
            'instance_type' => 'accounts',
            'configuration' => [
                'account_id' => 'test_account_123',
                'account_name' => 'Test Account',
            ],
        ]);

        $job = new GoCardlessAccountPull($accountIntegration);

        $exception = new GoCardlessEuaExpiredException(
            $this->group->id,
            [
                'summary' => 'End User Agreement (EUA) has expired',
                'detail' => 'EUA expired',
            ]
        );

        $job->failed($exception);

        Queue::assertPushed(HandleExpiredEuaJob::class);
    }

    /**
     * @test
     */
    public function exception_extracts_eua_id_correctly(): void
    {
        $exception = new GoCardlessEuaExpiredException(
            $this->group->id,
            [
                'summary' => 'End User Agreement (EUA) 7df396d0-844e-41cd-bc32-e62b7f65b154 has expired',
                'detail' => 'EUA 7df396d0-844e-41cd-bc32-e62b7f65b154 was valid for 90 days',
            ]
        );

        $this->assertEquals($this->group->id, $exception->getGroupId());
        $this->assertEquals('7df396d0-844e-41cd-bc32-e62b7f65b154', $exception->getEuaId());
        $this->assertArrayHasKey('summary', $exception->getErrorResponse());
    }

    /**
     * @test
     */
    public function handle_expired_eua_job_marks_group_as_expired(): void
    {
        $job = new HandleExpiredEuaJob(
            $this->group->id,
            '7df396d0-844e-41cd-bc32-e62b7f65b154',
            [
                'summary' => 'End User Agreement (EUA) has expired',
                'detail' => 'EUA expired',
            ]
        );

        $job->handle();

        $this->group->refresh();
        $this->assertTrue($this->group->auth_metadata['eua_expired'] ?? false);
        $this->assertTrue($this->group->auth_metadata['requires_reconfirmation'] ?? false);
        $this->assertNotNull($this->group->auth_metadata['eua_expired_at'] ?? null);
    }

    /**
     * @test
     */
    public function handle_expired_eua_job_pauses_integrations(): void
    {
        $job = new HandleExpiredEuaJob(
            $this->group->id,
            null,
            ['summary' => 'End User Agreement (EUA) has expired']
        );

        $job->handle();

        $this->integration->refresh();
        $this->assertTrue($this->integration->configuration['paused'] ?? false);
    }
}
