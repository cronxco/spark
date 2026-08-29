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

        Volt::test('admin.task-pipeline-overview')
            ->assertSee('Generate Embedding')
            ->set('taskSearch', 'generate_embedding')
            ->assertSee('Generate Embedding')
            ->set('taskSearch', 'no-task-matches-this-search')
            ->assertDontSee('Generate Embedding')
            ->assertSee('No tasks found');
    }

    #[Test]
    public function applies_to_filter_narrows_the_registered_tasks_table(): void
    {
        $this->actingAs($this->admin());

        Volt::test('admin.task-pipeline-overview')
            ->set('appliesToFilter', 'integration')
            ->assertDontSee('Generate Embedding')
            ->set('appliesToFilter', 'event')
            ->assertSee('Generate Embedding');
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

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->is_admin = true;
        $admin->save();

        return $admin;
    }
}
