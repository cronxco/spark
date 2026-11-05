<?php

namespace Tests\Unit\Jobs;

use App\Jobs\Outline\OutlinePullRecentDocuments;
use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

class OutlinePullRecentDocumentsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function job_can_be_instantiated(): void
    {
        $integration = $this->makeIntegration();
        $job = new OutlinePullRecentDocuments($integration);

        $this->assertInstanceOf(OutlinePullRecentDocuments::class, $job);
    }

    #[Test]
    public function returns_correct_service_name_and_job_type(): void
    {
        $integration = $this->makeIntegration();
        $job = new OutlinePullRecentDocuments($integration);

        $reflection = new ReflectionClass($job);
        $serviceNameMethod = $reflection->getMethod('getServiceName');
        $serviceNameMethod->setAccessible(true);
        $jobTypeMethod = $reflection->getMethod('getJobType');
        $jobTypeMethod->setAccessible(true);

        $this->assertSame('outline', $serviceNameMethod->invoke($job));
        $this->assertSame('pull_recent_documents', $jobTypeMethod->invoke($job));
    }

    private function makeIntegration(array $config = []): Integration
    {
        /** @var Integration $integration */
        $integration = Integration::factory()->create([
            'service' => 'outline',
            'instance_type' => 'recent_documents',
            'configuration' => array_merge([
                'api_url' => 'https://example-outline.test',
                'access_token' => 'test-token',
                'daynotes_collection_id' => '5622670a-e725-437d-b747-a17905038df8',
                'poll_interval_minutes' => 120,
            ], $config),
        ]);

        return $integration;
    }
}
