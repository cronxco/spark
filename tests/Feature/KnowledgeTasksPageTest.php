<?php

namespace Tests\Feature;

use App\Livewire\KnowledgeTasks;
use App\Models\User;
use App\Services\Knowledge\TaskService;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class KnowledgeTasksPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 11)->setTime(12, 0));
    }

    #[Test]
    public function the_page_lists_tasks_and_keeps_the_form_closed_until_asked(): void
    {
        $user = User::factory()->create();
        $this->capture($user, ['title' => 'Book the dentist', 'availability_on' => '2026-10-11']);

        Livewire::actingAs($user)->test(KnowledgeTasks::class)
            ->assertSee('Book the dentist')
            ->assertSet('showTaskForm', false)
            ->call('newTask')
            ->assertSet('showTaskForm', true)
            ->assertSet('editing', null);
    }

    #[Test]
    public function saving_a_new_task_closes_the_form(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(KnowledgeTasks::class)
            ->call('newTask')
            ->set('title', 'Water the plants')
            ->call('save')
            ->assertSet('showTaskForm', false)
            ->assertSet('title', '');
    }

    #[Test]
    public function an_invalid_task_keeps_the_form_open(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(KnowledgeTasks::class)
            ->call('newTask')
            ->set('title', '')
            ->call('save')
            ->assertSet('showTaskForm', true);
    }

    #[Test]
    public function editing_a_task_opens_the_form_with_its_values(): void
    {
        $user = User::factory()->create();
        $task = $this->capture($user, ['title' => 'Renew passport']);

        Livewire::actingAs($user)->test(KnowledgeTasks::class)
            ->call('edit', $task['id'])
            ->assertSet('showTaskForm', true)
            ->assertSet('title', 'Renew passport');
    }

    #[Test]
    public function bulk_actions_apply_to_the_selection_and_clear_it(): void
    {
        $user = User::factory()->create();
        $task = $this->capture($user, ['title' => 'Send invoice', 'availability_on' => '2026-10-11']);

        Livewire::actingAs($user)->test(KnowledgeTasks::class)
            ->set('selected', [$task['id'] . '|' . $task['version']])
            ->assertSee('1 selected')
            ->call('bulk', 'complete')
            ->assertSet('selected', []);

        $payload = app(TaskService::class)->payload(app(TaskService::class)->find($user, $task['id']));
        $this->assertSame('completed', $payload['status']);
    }

    #[Test]
    public function projects_have_their_own_view_and_form(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(KnowledgeTasks::class)
            ->set('section', 'projects')
            ->assertSee('No projects yet')
            ->call('newProject')
            ->assertSet('showProjectForm', true)
            ->set('projectTitle', 'Move house')
            ->call('createProject')
            ->assertSet('showProjectForm', false)
            ->assertSee('Move house');
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function capture(User $user, array $fields = []): array
    {
        return app(TaskService::class)->create($user, array_merge(['title' => 'Task', 'client_mutation_id' => (string) Str::uuid()], $fields));
    }
}
