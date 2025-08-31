<?php

namespace Tests\Feature;

use App\Integrations\GitHub\GitHubPlugin;
use App\Integrations\PluginRegistry;
use App\Models\Integration;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FetchCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Register the GitHub plugin for testing
        PluginRegistry::register(GitHubPlugin::class);
    }

    #[Test]
    public function fetch_command_only_updates_integrations_that_need_updating()
    {
        $user = User::factory()->create();

        // Integration that needs update (never updated)
        $integration1 = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'configuration' => ['update_frequency_minutes' => 15],
            'last_successful_update_at' => null,
        ]);

        // Integration that doesn't need update
        $integration2 = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'configuration' => ['update_frequency_minutes' => 15],
            'last_successful_update_at' => Carbon::now()->subMinutes(10),
        ]);

        $result = Artisan::call('integrations:fetch');

        $this->assertEquals(0, $result);

        // Check that only the first integration was marked as triggered
        $integration1->refresh();
        $integration2->refresh();

        $this->assertNotNull($integration1->last_triggered_at);
        $this->assertNull($integration2->last_triggered_at);
    }

    #[Test]
    public function fetch_command_with_force_updates_all_integrations()
    {
        $user = User::factory()->create();

        // Integration that doesn't need update
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'configuration' => ['update_frequency_minutes' => 15],
            'last_successful_update_at' => Carbon::now()->subMinutes(10),
        ]);

        $result = Artisan::call('integrations:fetch', ['--force' => true]);

        $this->assertEquals(0, $result);

        // Check that the integration was marked as triggered even though it didn't need updating
        $integration->refresh();
        $this->assertNotNull($integration->last_triggered_at);
    }

    #[Test]
    public function fetch_command_returns_no_integrations_message_when_none_need_updating()
    {
        $user = User::factory()->create();

        // Integration that doesn't need update
        Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'configuration' => ['update_frequency_minutes' => 15],
            'last_successful_update_at' => Carbon::now()->subMinutes(10),
        ]);

        $result = Artisan::call('integrations:fetch');

        $this->assertEquals(0, $result);

        // The command should output that no integrations need updating
        $output = Artisan::output();
        $this->assertStringContainsString('No integrations need updating', $output);
    }
}
