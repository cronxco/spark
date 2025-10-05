<?php

namespace Tests\Unit\Traits;

use App\Models\Integration;
use App\Models\User;
use App\Traits\MigrationPauser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MigrationPauserTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_pause_an_integration_during_migration(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'outline',
            'configuration' => [],
        ]);

        $this->assertFalse($integration->isPaused());

        // Use an anonymous class that uses the trait
        $testHelper = new class
        {
            use MigrationPauser;
        };
        $testHelper::pauseDuringMigration($integration);

        $integration->refresh();
        $this->assertTrue($integration->isPaused());
        $this->assertEquals(true, $integration->configuration['paused']);
    }

    /** @test */
    public function it_can_unpause_an_integration_after_migration(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'outline',
            'configuration' => ['paused' => true],
        ]);

        $this->assertTrue($integration->isPaused());

        $testHelper = new class
        {
            use MigrationPauser;
        };
        $testHelper::unpauseAfterMigration($integration);

        $integration->refresh();
        $this->assertFalse($integration->isPaused());
        $this->assertEquals(false, $integration->configuration['paused']);
    }

    /** @test */
    public function it_handles_integrations_without_existing_configuration(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'outline',
            'configuration' => null,
        ]);

        $testHelper = new class
        {
            use MigrationPauser;
        };
        $testHelper::pauseDuringMigration($integration);

        $integration->refresh();
        $this->assertTrue($integration->isPaused());
        $this->assertEquals(true, $integration->configuration['paused']);
    }
}
