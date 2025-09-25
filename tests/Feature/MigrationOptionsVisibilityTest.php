<?php

namespace Tests\Feature;

use App\Integrations\PluginRegistry;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MigrationOptionsVisibilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function migration_options_shown_for_supported_plugins(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'monzo', // Monzo supports migration
        ]);

        $this->actingAs($user);

        $response = $this->get(route('integrations.onboarding', ['group' => $group->id]));

        $response->assertStatus(200);
        $response->assertSee('Run initial historical import now');
        $response->assertSee('Historic import time limit');
    }

    /**
     * @test
     */
    public function migration_options_hidden_for_unsupported_plugins(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'apple_health', // Apple Health doesn't support migration
        ]);

        $this->actingAs($user);

        $response = $this->get(route('integrations.onboarding', ['group' => $group->id]));

        $response->assertStatus(200);
        $response->assertDontSee('Run initial historical import now');
        $response->assertDontSee('Historic import time limit');
    }

    /**
     * @test
     */
    public function plugin_migration_support_methods(): void
    {
        // Test plugins that support migration
        $migrationSupportedPlugins = ['monzo', 'gocardless', 'oura', 'spotify', 'github', 'outline'];

        foreach ($migrationSupportedPlugins as $pluginIdentifier) {
            $pluginClass = PluginRegistry::getPlugin($pluginIdentifier);
            $this->assertNotNull($pluginClass, "Plugin {$pluginIdentifier} should be registered");
            $this->assertTrue($pluginClass::supportsMigration(), "Plugin {$pluginIdentifier} should support migration");
        }

        // Test plugins that don't support migration
        $migrationUnsupportedPlugins = ['apple_health', 'hevy', 'task'];

        foreach ($migrationUnsupportedPlugins as $pluginIdentifier) {
            $pluginClass = PluginRegistry::getPlugin($pluginIdentifier);
            if ($pluginClass) {
                $this->assertFalse($pluginClass::supportsMigration(), "Plugin {$pluginIdentifier} should not support migration");
            }
        }
    }

    /**
     * @test
     */
    public function all_migration_supported_plugins_have_migration_implementation(): void
    {
        $migrationSupportedPlugins = ['monzo', 'gocardless', 'oura', 'spotify', 'github', 'outline'];

        foreach ($migrationSupportedPlugins as $pluginIdentifier) {
            $pluginClass = PluginRegistry::getPlugin($pluginIdentifier);
            $this->assertNotNull($pluginClass, "Plugin {$pluginIdentifier} should be registered");

            // Check that the plugin actually has migration support in StartIntegrationMigration
            $this->assertTrue($pluginClass::supportsMigration(), "Plugin {$pluginIdentifier} should support migration");

            // Verify the plugin is handled in StartIntegrationMigration
            $startMigrationFile = file_get_contents(app_path('Jobs/Migrations/StartIntegrationMigration.php'));
            $this->assertStringContainsString("service === '{$pluginIdentifier}'", $startMigrationFile,
                "Plugin {$pluginIdentifier} should be handled in StartIntegrationMigration");
        }
    }
}
