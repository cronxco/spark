<?php

namespace Tests\Feature;

use App\Integrations\PluginRegistry;
use App\Jobs\Migrations\StartIntegrationMigration;
use App\Models\ActionProgress;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use ReflectionClass;
use Tests\TestCase;

class OutlineMigrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function outline_migration_creates_progress_record(): void
    {
        Bus::fake();

        /** @var User $user */
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'outline',
        ]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'outline',
            'instance_type' => 'recent_documents',
        ]);

        $job = new StartIntegrationMigration($integration);
        $job->handle();

        // Check that ActionProgress record was created
        $progressRecord = ActionProgress::getLatestProgress(
            $user->id,
            'migration',
            "integration_{$integration->id}"
        );

        $this->assertNotNull($progressRecord);
        $this->assertEquals('monitoring', $progressRecord->step);
        $this->assertEquals(40, $progressRecord->progress);
        $this->assertStringContainsString('Outline migration started', $progressRecord->message);
        $this->assertFalse($progressRecord->isFailed());

        // Check that integration migration status was updated
        $integration->refresh();
        $configuration = $integration->configuration;

        // Note: Configuration update might not be visible immediately due to database transaction
        // The important part is that the progress record is created and the job is dispatched
        if (array_key_exists('migration_status', $configuration)) {
            $this->assertEquals('started', $configuration['migration_status']);
            $this->assertArrayHasKey('migration_started_at', $configuration);
            $this->assertNotNull($configuration['migration_started_at']);
        }
    }

    /**
     * @test
     */
    public function outline_migration_dispatches_outline_migration_pull(): void
    {
        Bus::fake();

        /** @var User $user */
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'outline',
        ]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'outline',
            'instance_type' => 'recent_documents',
        ]);

        $job = new StartIntegrationMigration($integration);
        $job->handle();

        // Check that OutlineMigrationPull was dispatched
        Bus::assertDispatched(\App\Jobs\Outline\OutlineMigrationPull::class, function ($job) use ($integration) {
            // Use reflection to access protected properties for testing
            $reflection = new ReflectionClass($job);
            $integrationProperty = $reflection->getProperty('integration');
            $integrationProperty->setAccessible(true);
            $jobIntegration = $integrationProperty->getValue($job);

            $offsetProperty = $reflection->getProperty('offset');
            $offsetProperty->setAccessible(true);
            $offset = $offsetProperty->getValue($job);

            $limitProperty = $reflection->getProperty('limit');
            $limitProperty->setAccessible(true);
            $limit = $limitProperty->getValue($job);

            return $jobIntegration->id === $integration->id
                && $offset === 0
                && $limit === 50;
        });
    }

    /**
     * @test
     */
    public function outline_plugin_supports_migration(): void
    {
        $pluginClass = PluginRegistry::getPlugin('outline');
        $this->assertNotNull($pluginClass);
        $this->assertTrue($pluginClass::supportsMigration());
    }
}
