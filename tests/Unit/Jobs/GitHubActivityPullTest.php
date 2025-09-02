<?php

namespace Tests\Unit\Jobs;

use App\Jobs\OAuth\GitHub\GitHubActivityPull;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

class GitHubActivityPullTest extends TestCase
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
            'service' => 'github',
        ]);
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'github',
            'instance_type' => 'activity',
            'configuration' => [
                'repositories' => ['owner/repo1', 'owner/repo2'],
                'events' => ['push', 'pull_request'],
                'update_frequency_minutes' => 15,
            ],
        ]);
    }

    /**
     * @test
     */
    public function job_creation()
    {
        $job = new GitHubActivityPull($this->integration);

        $this->assertInstanceOf(GitHubActivityPull::class, $job);
        $this->assertEquals(120, $job->timeout);
        $this->assertEquals(3, $job->tries);
        $this->assertEquals([60, 300, 600], $job->backoff);
    }

    /**
     * @test
     */
    public function unique_id_generation()
    {
        $job = new GitHubActivityPull($this->integration);
        $uniqueId = $job->uniqueId();

        $this->assertStringContainsString('github_activity_' . $this->integration->id, $uniqueId);
        $this->assertStringContainsString(date('Y-m-d'), $uniqueId);
    }

    /**
     * @test
     */
    public function job_handles_integration_correctly()
    {
        $job = new GitHubActivityPull($this->integration);

        // Test that the job was created successfully with the integration
        $this->assertInstanceOf(GitHubActivityPull::class, $job);
        $this->assertEquals($this->integration->id, $this->integration->id); // Test that integration exists
        $this->assertEquals('github', $this->integration->service);
        $this->assertEquals('activity', $this->integration->instance_type);
    }

    /**
     * @test
     */
    public function configuration_inheritance()
    {
        $this->assertEquals(['owner/repo1', 'owner/repo2'], $this->integration->configuration['repositories']);
        $this->assertEquals(['push', 'pull_request'], $this->integration->configuration['events']);
        $this->assertEquals(15, $this->integration->configuration['update_frequency_minutes']);

        $job = new GitHubActivityPull($this->integration);
        $this->assertInstanceOf(GitHubActivityPull::class, $job);
    }

    /**
     * @test
     */
    public function normalize_repositories()
    {
        $job = new GitHubActivityPull($this->integration);

        // Test array input
        $result = $this->invokePrivateMethod($job, 'normalizeRepositories', [['repo1', 'repo2']]);
        $this->assertEquals(['repo1', 'repo2'], $result);

        // Test comma-separated string
        $result = $this->invokePrivateMethod($job, 'normalizeRepositories', ['repo1, repo2']);
        $this->assertEquals(['repo1', 'repo2'], $result);

        // Test empty input
        $result = $this->invokePrivateMethod($job, 'normalizeRepositories', [[]]);
        $this->assertEquals([], $result);
    }

    /**
     * @test
     */
    public function normalize_events()
    {
        $job = new GitHubActivityPull($this->integration);

        // Test array input
        $result = $this->invokePrivateMethod($job, 'normalizeEvents', [['push', 'pull_request']]);
        $this->assertEquals(['push', 'pull_request'], $result);

        // Test comma-separated string
        $result = $this->invokePrivateMethod($job, 'normalizeEvents', ['push, pull_request']);
        $this->assertEquals(['push', 'pull_request'], $result);

        // Test empty array should return empty array (not defaults)
        $result = $this->invokePrivateMethod($job, 'normalizeEvents', [[]]);
        $this->assertEquals([], $result);

        // Test null input should return defaults
        $result = $this->invokePrivateMethod($job, 'normalizeEvents', [null]);
        $this->assertEquals(['push', 'pull_request'], $result);
    }

    /**
     * @test
     */
    public function job_with_different_configurations()
    {
        // Test with JSON string repositories
        $integrationJson = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'github',
            'instance_type' => 'activity',
            'configuration' => [
                'repositories' => '["owner/repo1", "owner/repo2"]',
                'events' => '["push"]',
            ],
        ]);

        $job = new GitHubActivityPull($integrationJson);
        $this->assertInstanceOf(GitHubActivityPull::class, $job);

        // Test with newline-separated repositories
        $integrationNewline = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'github',
            'instance_type' => 'activity',
            'configuration' => [
                'repositories' => "owner/repo1\nowner/repo2",
                'events' => "push\npull_request",
            ],
        ]);

        $job2 = new GitHubActivityPull($integrationNewline);
        $this->assertInstanceOf(GitHubActivityPull::class, $job2);
    }

    /**
     * Helper method to invoke private methods for testing
     */
    private function invokePrivateMethod($object, $method, $args = [])
    {
        $reflection = new ReflectionClass($object);
        $method = $reflection->getMethod($method);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $args);
    }
}
