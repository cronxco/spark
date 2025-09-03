<?php

namespace Tests\Unit\Jobs;

use App\Jobs\Data\Oura\OuraActivityData;
use App\Jobs\OAuth\Oura\OuraActivityPull;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OuraActivityPullTest extends TestCase
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
            'service' => 'oura',
            'access_token' => 'test_access_token',
        ]);
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'oura',
            'instance_type' => 'activity',
        ]);
    }

    /**
     * @test
     */
    public function fetch_activity_success()
    {
        Queue::fake();

        $mockResponse = [
            'data' => [
                [
                    'day' => '2024-01-01',
                    'score' => 85,
                    'contributors' => [
                        'steps' => 12000,
                        'cal_total' => 450,
                    ],
                ],
            ],
        ];

        Http::fake(function ($request) use ($mockResponse) {
            if (str_contains($request->url(), 'api.ouraring.com/v2/usercollection/daily_activity')) {
                return Http::response($mockResponse, 200);
            }

            return Http::response(['error' => 'Not found'], 404);
        });

        $job = $this->createTestableJob();
        $result = $job->publicFetchData();

        $this->assertEquals($mockResponse['data'], $result);
    }

    /**
     * @test
     */
    public function dispatch_processing_jobs()
    {
        Queue::fake();

        $activityData = [
            [
                'day' => '2024-01-01',
                'score' => 85,
            ],
        ];

        $job = $this->createTestableJob();
        $job->publicDispatchProcessingJobs($activityData);

        Queue::assertPushed(OuraActivityData::class, function ($job) use ($activityData) {
            return $job->getIntegration()->id === $this->integration->id
                && $job->getRawData() === $activityData;
        });
    }

    /**
     * @test
     */
    public function job_metadata()
    {
        $job = $this->createTestableJob();

        $this->assertEquals('oura', $job->publicGetServiceName());
        $this->assertEquals('activity', $job->publicGetJobType());
        $this->assertEquals(120, $job->timeout);
        $this->assertEquals(3, $job->tries);
    }

    private function createTestableJob(): OuraActivityPull
    {
        return new class($this->integration) extends OuraActivityPull
        {
            public function publicFetchData()
            {
                // Create a mock plugin that doesn't call protected methods
                $mockPlugin = $this->createMockPlugin();
                // Replace the plugin with our mock
                $this->plugin = $mockPlugin;

                return $this->fetchData();
            }

            public function publicDispatchProcessingJobs(array $data)
            {
                return $this->dispatchProcessingJobs($data);
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

                    public $apiBase = 'https://api.ouraring.com/v2';

                    public function logApiRequest(...$args) {}

                    public function logApiResponse(...$args) {}
                };
            }
        };
    }
}
