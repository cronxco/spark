<?php

namespace Tests\Unit\Jobs;

use App\Jobs\Data\Monzo\MonzoAccountData;
use App\Jobs\OAuth\Monzo\MonzoAccountPull;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MonzoAccountPullTest extends TestCase
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
            'service' => 'monzo',
            'access_token' => 'test_access_token',
        ]);
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'monzo',
            'instance_type' => 'accounts',
        ]);
    }

    /**
     * @test
     */
    public function fetch_accounts_success()
    {
        Queue::fake();

        $mockResponse = [
            'accounts' => [
                [
                    'id' => 'acc_123',
                    'type' => 'uk_retail',
                    'currency' => 'GBP',
                ],
            ],
        ];

        Http::fake([
            'api.monzo.com/accounts' => Http::response($mockResponse, 200),
        ]);

        $job = $this->createTestableJob();
        $result = $job->publicFetchData();

        $this->assertEquals($mockResponse['accounts'], $result);
    }

    /**
     * @test
     */
    public function dispatch_processing_jobs()
    {
        Queue::fake();

        $accounts = [
            [
                'id' => 'acc_123',
                'type' => 'uk_retail',
                'currency' => 'GBP',
            ],
        ];

        $job = $this->createTestableJob();
        $job->publicDispatchProcessingJobs($accounts);

        Queue::assertPushed(MonzoAccountData::class, function ($job) use ($accounts) {
            return $job->getIntegration()->id === $this->integration->id
                && $job->getRawData() === $accounts[0];
        });
    }

    /**
     * @test
     */
    public function job_metadata()
    {
        $job = $this->createTestableJob();

        $this->assertEquals('monzo', $job->publicGetServiceName());
        $this->assertEquals('accounts', $job->publicGetJobType());
        $this->assertEquals(120, $job->timeout);
        $this->assertEquals(3, $job->tries);
    }

    private function createTestableJob(): MonzoAccountPull
    {
        return new class($this->integration) extends MonzoAccountPull
        {
            public function publicFetchData()
            {
                // Create a mock plugin that doesn't call protected methods
                $mockPlugin = $this->createMockPlugin();
                // Replace the plugin with our mock
                $this->plugin = $mockPlugin;

                return $this->fetchData();
            }

            public function publicDispatchProcessingJobs(array $accounts)
            {
                return $this->dispatchProcessingJobs($accounts);
            }

            public function publicGetServiceName()
            {
                return $this->getServiceName();
            }

            public function publicGetJobType()
            {
                return $this->getJobType();
            }

            public function getIntegration()
            {
                return $this->integration;
            }

            public function getRawData()
            {
                return $this->rawData;
            }

            private function createMockPlugin()
            {
                return new class
                {
                    public function authHeaders($integration)
                    {
                        return ['Authorization' => 'Bearer test-token'];
                    }
                    public $apiBase = 'https://api.monzo.com';

                    public function logApiRequest(...$args) {}

                    public function logApiResponse(...$args) {}
                };
            }
        };
    }
}
