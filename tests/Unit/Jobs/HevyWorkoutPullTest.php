<?php

namespace Tests\Unit\Jobs;

use App\Jobs\Data\Hevy\HevyWorkoutData;
use App\Jobs\OAuth\Hevy\HevyWorkoutPull;
use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class HevyWorkoutPullTest extends TestCase
{
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->integration = Integration::factory()->create([
            'service' => 'hevy',
            'configuration' => [
                'days_back' => 7,
                'api_key' => 'test-api-key',
            ],
        ]);
    }

    /**
     * @test
     */
    public function job_creation()
    {
        $job = $this->createTestableJob();

        $this->assertEquals('hevy', $job->publicGetServiceName());
        $this->assertEquals('workout', $job->publicGetJobType());
    }

    /**
     * @test
     */
    public function unique_id_generation()
    {
        $job = $this->createTestableJob();
        $uniqueId = $job->uniqueId();

        $expectedPattern = '/^hevy_workout_[a-f0-9-]+_\d{4}-\d{2}-\d{2}/';
        $this->assertMatchesRegularExpression($expectedPattern, $uniqueId);
    }

    /**
     * @test
     */
    public function fetch_data_success()
    {
        $mockResponse = [
            'data' => [
                [
                    'id' => 'workout_123',
                    'title' => 'Morning Workout',
                    'start_time' => '2024-01-15T08:00:00Z',
                    'total_volume' => 1500.5,
                    'duration_seconds' => 3600,
                    'exercises' => [
                        [
                            'name' => 'Bench Press',
                            'sets' => [
                                [
                                    'reps' => 10,
                                    'weight' => 80.5,
                                ],
                                [
                                    'reps' => 8,
                                    'weight' => 85.0,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            'api.hevyapp.com/v1/workouts*' => Http::response($mockResponse, 200),
        ]);

        $job = $this->createTestableJob();
        $result = $job->publicFetchData();

        $this->assertEquals($mockResponse, $result);
    }

    /**
     * @test
     */
    public function dispatch_processing_jobs()
    {
        Queue::fake();

        $rawData = [
            'data' => [
                [
                    'id' => 'workout_123',
                    'title' => 'Test Workout',
                ],
            ],
        ];

        $job = $this->createTestableJob();
        $job->publicDispatchProcessingJobs($rawData);

        Queue::assertPushed(HevyWorkoutData::class, function ($job) {
            return $job->getIntegration()->id === $this->integration->id
                && isset($job->getRawData()['data']);
        });
    }

    /**
     * @test
     */
    public function api_error_handling()
    {
        Http::fake([
            'api.hevyapp.com/v1/workouts*' => Http::response(['error' => 'Invalid API key'], 401),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Hevy API request failed with status 401');

        $job = $this->createTestableJob();
        $job->publicFetchData();
    }

    /**
     * @test
     */
    public function empty_workout_data()
    {
        Queue::fake();

        $rawData = ['data' => []];

        $job = $this->createTestableJob();
        $job->publicDispatchProcessingJobs($rawData);

        Queue::assertNotPushed(HevyWorkoutData::class);
    }

    private function createTestableJob(): HevyWorkoutPull
    {
        return new class($this->integration) extends HevyWorkoutPull
        {
            public function publicFetchData()
            {
                return $this->fetchData();
            }

            public function publicDispatchProcessingJobs(array $rawData)
            {
                return $this->dispatchProcessingJobs($rawData);
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
        };
    }
}
