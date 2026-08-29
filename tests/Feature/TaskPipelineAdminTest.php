<?php

namespace Tests\Feature;

use App\Jobs\TaskPipeline\ProcessTaskPipelineJob;
use App\Models\Event;
use App\Models\TaskExecution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TaskPipelineAdminTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function admin_task_pipeline_page_loads_for_admin_user(): void
    {
        $response = $this->actingAs($this->admin())
            ->get(route('admin.task-pipeline.index'));

        $response->assertSuccessful();
        $response->assertSee('Task Pipeline');
        $response->assertSee('Generate Embedding');
    }

    #[Test]
    public function admin_task_pipeline_page_is_forbidden_for_non_admin(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('admin.task-pipeline.index'))
            ->assertForbidden();
    }

    #[Test]
    public function admin_task_pipeline_page_requires_authentication(): void
    {
        $response = $this->get(route('admin.task-pipeline.index'));

        $response->assertRedirect(route('login'));
    }

    #[Test]
    public function task_search_narrows_the_registered_tasks_table(): void
    {
        $this->actingAs($this->admin());

        $component = Volt::test('admin.task-pipeline-overview');
        $hasGenerateEmbedding = fn () => $component->instance()->filteredTasks->contains(fn ($task) => $task->key === 'generate_embedding');

        $this->assertTrue($hasGenerateEmbedding());

        $component->set('taskSearch', 'generate_embedding');
        $this->assertTrue($hasGenerateEmbedding());

        $component->set('taskSearch', 'no-task-matches-this-search');
        $this->assertFalse($hasGenerateEmbedding());
        $component->assertSee('No tasks found');
    }

    #[Test]
    public function applies_to_filter_narrows_the_registered_tasks_table(): void
    {
        $this->actingAs($this->admin());

        $component = Volt::test('admin.task-pipeline-overview')
            ->set('appliesToFilter', 'integration');
        $hasGenerateEmbedding = fn () => $component->instance()->filteredTasks->contains(fn ($task) => $task->key === 'generate_embedding');

        $this->assertFalse($hasGenerateEmbedding());

        $component->set('appliesToFilter', 'event');
        $this->assertTrue($hasGenerateEmbedding());
    }

    #[Test]
    public function retry_failure_dispatches_the_task_pipeline_job_for_an_event_backed_failure(): void
    {
        Queue::fake();

        $user = $this->admin();
        $event = Event::factory()->create();
        $execution = TaskExecution::factory()->create([
            'user_id' => $user->id,
            'entity_type' => 'event',
            'entity_id' => $event->id,
            'task_key' => 'generate_embedding',
            'status' => 'failed',
            'error' => 'Something went wrong',
        ]);

        $this->actingAs($user);

        Volt::test('admin.task-pipeline-overview')
            ->call('retryFailure', $execution->id)
            ->assertHasNoErrors();

        Queue::assertPushed(ProcessTaskPipelineJob::class);
    }

    #[Test]
    public function retry_failure_reports_an_error_for_a_non_event_backed_failure(): void
    {
        Queue::fake();

        $user = $this->admin();
        $execution = TaskExecution::factory()->create([
            'user_id' => $user->id,
            'entity_type' => 'block',
            'entity_id' => (string) Str::uuid(),
            'task_key' => 'generate_embedding',
            'status' => 'failed',
        ]);

        $this->actingAs($user);

        Volt::test('admin.task-pipeline-overview')
            ->call('retryFailure', $execution->id);

        Queue::assertNotPushed(ProcessTaskPipelineJob::class);
    }

    #[Test]
    public function active_executions_only_includes_non_terminal_statuses(): void
    {
        $this->actingAs($this->admin());

        $running = TaskExecution::factory()->create(['status' => 'running', 'task_key' => 'generate_embedding']);
        $waiting = TaskExecution::factory()->create(['status' => 'waiting', 'task_key' => 'generate_embedding']);
        $success = TaskExecution::factory()->create(['status' => 'success', 'task_key' => 'generate_embedding']);
        $failed = TaskExecution::factory()->create(['status' => 'failed', 'task_key' => 'generate_embedding']);

        $activeIds = Volt::test('admin.task-pipeline-overview')
            ->instance()
            ->activeExecutions
            ->pluck('id')
            ->all();

        $this->assertContains($running->id, $activeIds);
        $this->assertContains($waiting->id, $activeIds);
        $this->assertNotContains($success->id, $activeIds);
        $this->assertNotContains($failed->id, $activeIds);
    }

    #[Test]
    public function active_status_filter_narrows_the_active_tab(): void
    {
        $this->actingAs($this->admin());

        $running = TaskExecution::factory()->create(['status' => 'running', 'task_key' => 'generate_embedding']);
        $blocked = TaskExecution::factory()->create(['status' => 'blocked', 'task_key' => 'generate_embedding']);

        $activeIds = Volt::test('admin.task-pipeline-overview')
            ->set('activeStatusFilter', 'blocked')
            ->instance()
            ->activeExecutions
            ->pluck('id')
            ->all();

        $this->assertContains($blocked->id, $activeIds);
        $this->assertNotContains($running->id, $activeIds);
    }

    #[Test]
    public function stats_current_state_counts_are_not_time_windowed(): void
    {
        $this->actingAs($this->admin());

        $stalePending = TaskExecution::factory()->create(['status' => 'pending', 'task_key' => 'generate_embedding']);
        $stalePending->timestamps = false;
        $stalePending->forceFill(['updated_at' => now()->subDays(10)])->saveQuietly();

        $stats = Volt::test('admin.task-pipeline-overview')
            ->set('window', '24h')
            ->instance()
            ->stats;

        $this->assertSame(1, $stats['pending']);
    }

    #[Test]
    public function stuck_heuristic_flags_stale_running_or_pending_executions(): void
    {
        $this->actingAs($this->admin());

        $stuck = TaskExecution::factory()->create(['status' => 'running', 'task_key' => 'generate_embedding']);
        $stuck->timestamps = false;
        $stuck->forceFill(['updated_at' => now()->subMinutes(30)])->saveQuietly();

        $fresh = TaskExecution::factory()->create(['status' => 'running', 'task_key' => 'generate_embedding']);

        $instance = Volt::test('admin.task-pipeline-overview')->instance();

        $this->assertTrue($instance->isStuck($stuck->fresh()));
        $this->assertFalse($instance->isStuck($fresh->fresh()));
        $this->assertSame(1, $instance->stats['stuck']);
    }

    #[Test]
    public function recent_executions_tab_filters_by_status(): void
    {
        $this->actingAs($this->admin());

        $success = TaskExecution::factory()->create(['status' => 'success', 'task_key' => 'generate_embedding']);
        $failed = TaskExecution::factory()->create(['status' => 'failed', 'task_key' => 'generate_embedding']);

        $ids = Volt::test('admin.task-pipeline-overview')
            ->set('execStatusFilter', 'success')
            ->instance()
            ->recentExecutions
            ->pluck('id')
            ->all();

        $this->assertContains($success->id, $ids);
        $this->assertNotContains($failed->id, $ids);
    }

    #[Test]
    public function recent_executions_tab_paginates(): void
    {
        $this->actingAs($this->admin());

        TaskExecution::factory()->count(15)->create(['task_key' => 'generate_embedding', 'status' => 'success']);

        $page = Volt::test('admin.task-pipeline-overview')
            ->set('perPage', 10)
            ->instance()
            ->recentExecutions;

        $this->assertCount(10, $page);
        $this->assertSame(15, $page->total());
    }

    #[Test]
    public function execution_details_modal_shows_the_selected_execution(): void
    {
        $this->actingAs($this->admin());

        $execution = TaskExecution::factory()->create([
            'task_key' => 'generate_embedding',
            'status' => 'failed',
            'error' => 'Boom went the task',
        ]);

        Volt::test('admin.task-pipeline-overview')
            ->call('showExecutionDetails', $execution->id)
            ->assertSet('showExecutionModal', true)
            ->assertSee('Boom went the task')
            ->call('closeExecutionModal')
            ->assertSet('showExecutionModal', false);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->is_admin = true;
        $admin->save();

        return $admin;
    }
}
