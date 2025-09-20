<?php

namespace Tests\Unit\Jobs;

use App\Jobs\Outline\OutlineMigrationPull;
use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

class OutlineMigrationPullTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function job_can_be_instantiated(): void
    {
        $integration = $this->makeIntegration();
        $job = new OutlineMigrationPull($integration, 0, 50);

        $this->assertInstanceOf(OutlineMigrationPull::class, $job);
    }

    #[Test]
    public function generates_unique_id_correctly(): void
    {
        $integration = $this->makeIntegration();
        $job = new OutlineMigrationPull($integration, 100, 50);

        $expectedId = 'outline_migration_' . $integration->id . '_100';
        $this->assertSame($expectedId, $job->uniqueId());
    }

    #[Test]
    public function returns_correct_service_name_and_job_type(): void
    {
        $integration = $this->makeIntegration();
        $job = new OutlineMigrationPull($integration, 0, 50);

        $reflection = new ReflectionClass($job);
        $serviceNameMethod = $reflection->getMethod('getServiceName');
        $serviceNameMethod->setAccessible(true);
        $jobTypeMethod = $reflection->getMethod('getJobType');
        $jobTypeMethod->setAccessible(true);

        $this->assertSame('outline', $serviceNameMethod->invoke($job));
        $this->assertSame('migration', $jobTypeMethod->invoke($job));
    }

    private function makeIntegration(array $config = []): Integration
    {
        /** @var Integration $integration */
        $integration = Integration::factory()->create([
            'service' => 'outline',
            'instance_type' => 'migration',
            'configuration' => array_merge([
                'api_url' => 'https://example-outline.test',
                'access_token' => 'test-token',
                'daynotes_collection_id' => '5622670a-e725-437d-b747-a17905038df8',
            ], $config),
        ]);

        return $integration;
    }
}
