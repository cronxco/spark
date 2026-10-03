<?php

namespace Tests\Feature\Integrations;

use App\Actions\DispatchIntegrationFetchJobs;
use App\Jobs\TaskPipeline\Tasks\RunIntegrationUpdateTask;
use App\Livewire\IntegrationDetails;
use App\Mcp\Servers\SparkServer;
use App\Mcp\Tools\TriggerIntegrationUpdateTool;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use App\Services\Api\ResourceVersion;
use App\Services\TaskPipeline\TaskRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * INT-02: an update that queues no fetch job used to report "triggered" (and
 * the scheduled task "success") on every surface. Each now says it did nothing.
 */
class NothingToDispatchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Integration $nothingToFetch;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);
        Queue::fake();

        $this->user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $this->user->id, 'service' => 'github']);
        $this->nothingToFetch = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => 'github',
            'instance_type' => 'not_a_type',
        ]);
    }

    #[Test]
    public function mobile_sync_answers_422_instead_of_triggered(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);
        $etag = app(ResourceVersion::class)->etag($this->nothingToFetch);

        $this->postJson("/api/v1/mobile/integrations/{$this->nothingToFetch->id}/sync", [], ['If-Match' => $etag])
            ->assertStatus(422)
            ->assertJsonPath('code', 'nothing_to_dispatch');
    }

    #[Test]
    public function mobile_service_sync_reports_the_instance_as_failed(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/integrations/sync', ['service' => 'github'])
            ->assertOk()
            ->assertJsonPath('integrations.0.status', 'failed')
            ->assertJsonPath('integrations.0.reason', 'nothing_to_dispatch')
            ->assertJsonPath('total_jobs_dispatched', 0);
    }

    #[Test]
    public function rest_trigger_answers_422(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson("/api/integrations/{$this->nothingToFetch->id}/trigger")
            ->assertStatus(422)
            ->assertJsonPath('code', 'nothing_to_dispatch');
    }

    #[Test]
    public function mcp_trigger_reports_the_instance_as_failed(): void
    {
        SparkServer::actingAs($this->user)
            ->tool(TriggerIntegrationUpdateTool::class, ['integration_id' => $this->nothingToFetch->id])
            ->assertOk()
            ->assertSee('"triggered": 0')
            ->assertSee('nothing_to_dispatch');
    }

    #[Test]
    public function the_web_update_button_shows_an_error(): void
    {
        $this->actingAs($this->user);

        $component = Livewire::test(IntegrationDetails::class, ['integration' => $this->nothingToFetch])
            ->call('triggerIntegrationUpdate');

        $js = json_encode($component->effects['xjs'] ?? []);
        $this->assertStringContainsString('nothing Spark can fetch', $js);
        $this->assertStringNotContainsString('Update triggered', $js);
    }

    #[Test]
    public function the_scheduled_task_fails_but_still_waits_for_the_next_interval(): void
    {
        $task = TaskRegistry::getTask('run_integration_update');

        try {
            (new RunIntegrationUpdateTask($this->nothingToFetch, $task))->handle();
            $this->fail('Expected the task to fail.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString(DispatchIntegrationFetchJobs::NOTHING_TO_DISPATCH, $e->getMessage());
        }

        $this->assertNotNull($this->nothingToFetch->fresh()->last_triggered_at);
    }
}
