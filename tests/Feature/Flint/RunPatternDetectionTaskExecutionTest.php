<?php

namespace Tests\Feature\Flint;

use App\Jobs\Flint\RunPatternDetectionJob;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\TaskExecution;
use App\Models\User;
use App\Services\AgentOrchestrationService;
use App\Services\TaskPipeline\TaskExecutionStore;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RunPatternDetectionTaskExecutionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function successful_detection_records_a_task_execution_anchored_to_the_flint_integration(): void
    {
        $user = User::factory()->create();
        $integration = $this->createFlintIntegration($user);

        $orchestration = Mockery::mock(AgentOrchestrationService::class);
        $orchestration->shouldReceive('runPatternDetection')
            ->once()
            ->with(Mockery::on(fn ($arg) => $arg->is($user)))
            ->andReturn(['pattern-one', 'pattern-two']);

        (new RunPatternDetectionJob($user))->handle($orchestration, app(TaskExecutionStore::class));

        $execution = TaskExecution::where('entity_type', 'integration')
            ->where('entity_id', $integration->id)
            ->where('task_key', 'flint_pattern_detection')
            ->firstOrFail();

        $this->assertSame('success', $execution->status);
        $this->assertSame(2, $execution->last_success['patterns_detected']);
        $this->assertSame($user->id, $execution->user_id);
    }

    #[Test]
    public function failed_detection_records_a_failed_task_execution_and_rethrows(): void
    {
        $user = User::factory()->create();
        $integration = $this->createFlintIntegration($user);

        $orchestration = Mockery::mock(AgentOrchestrationService::class);
        $orchestration->shouldReceive('runPatternDetection')
            ->once()
            ->andThrow(new Exception('LLM exploded'));

        $job = new RunPatternDetectionJob($user);

        try {
            $job->handle($orchestration, app(TaskExecutionStore::class));
            $this->fail('Expected the job to rethrow the exception.');
        } catch (Exception $e) {
            $this->assertSame('LLM exploded', $e->getMessage());
        }

        $execution = TaskExecution::where('entity_type', 'integration')
            ->where('entity_id', $integration->id)
            ->where('task_key', 'flint_pattern_detection')
            ->firstOrFail();

        $this->assertSame('failed', $execution->status);
        $this->assertSame('LLM exploded', $execution->error);
    }

    #[Test]
    public function retry_clears_the_previous_detection_error(): void
    {
        $user = User::factory()->create();
        $integration = $this->createFlintIntegration($user);
        $orchestration = Mockery::mock(AgentOrchestrationService::class);
        $orchestration->shouldReceive('runPatternDetection')
            ->once()
            ->andThrow(new Exception('LLM exploded'));

        $job = new RunPatternDetectionJob($user);
        $firstAttemptFailed = false;

        try {
            $job->handle($orchestration, app(TaskExecutionStore::class));
        } catch (Exception) {
            $firstAttemptFailed = true;
        }

        $this->assertTrue($firstAttemptFailed, 'Expected the first pattern detection attempt to fail.');

        $successfulOrchestration = Mockery::mock(AgentOrchestrationService::class);
        $successfulOrchestration->shouldReceive('runPatternDetection')
            ->once()
            ->andReturn([]);

        $job->handle($successfulOrchestration, app(TaskExecutionStore::class));

        $execution = TaskExecution::where('entity_type', 'integration')
            ->where('entity_id', $integration->id)
            ->where('task_key', 'flint_pattern_detection')
            ->firstOrFail();

        $this->assertSame('success', $execution->status);
        $this->assertNull($execution->error);
    }

    protected function createFlintIntegration(User $user): Integration
    {
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'flint',
        ]);

        return Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'flint',
        ]);
    }
}
