<?php

namespace Tests\Unit\Jobs;

use App\Jobs\Base\BaseFetchJob;
use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BaseFetchJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function unique_id_generation()
    {
        $integration = Integration::factory()->create([
            'service' => 'test_service',
        ]);

        $job = new class($integration) extends BaseFetchJob
        {
            protected function getServiceName(): string
            {
                return 'test_service';
            }

            protected function getJobType(): string
            {
                return 'test_type';
            }

            protected function fetchData(): array
            {
                return ['test' => 'data'];
            }

            protected function dispatchProcessingJobs(array $rawData): void
            {
                // No-op for test
            }
        };

        $uniqueId = $job->uniqueId();
        $expectedPattern = '/test_service_test_type_' . $integration->id . '_\d{4}-\d{2}-\d{2}/';

        $this->assertMatchesRegularExpression($expectedPattern, $uniqueId);
    }

    /**
     * @test
     */
    public function job_timeout_and_retries()
    {
        $integration = Integration::factory()->create();

        $job = new class($integration) extends BaseFetchJob
        {
            protected function getServiceName(): string
            {
                return 'test_service';
            }

            protected function getJobType(): string
            {
                return 'test_type';
            }

            protected function fetchData(): array
            {
                return ['test' => 'data'];
            }

            protected function dispatchProcessingJobs(array $rawData): void
            {
                // No-op for test
            }
        };

        $this->assertEquals(120, $job->timeout);
        $this->assertEquals(3, $job->tries);
        $this->assertEquals([60, 300, 600], $job->backoff);
    }
}
