<?php

namespace Tests\Feature;

use App\Jobs\RunIntegrationTask;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RunIntegrationTaskTest extends TestCase
{
    #[Test]
    public function runs_artisan_command_mode()
    {
        Artisan::shouldReceive('call')->once()->with('queue:prune-batches', Mockery::type('array'));

        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'task',
            'instance_type' => 'task',
            'configuration' => [
                'task_mode' => 'artisan',
                'task_command' => 'queue:prune-batches',
                'task_payload' => ['hours' => 24],
            ],
        ]);

        (new RunIntegrationTask($integration))->handle();
        $this->assertNotNull($integration->fresh()->last_successful_update_at);
    }
}
