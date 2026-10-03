<?php

namespace Tests\Feature\EventsObjectsBlocks;

use App\Jobs\TaskPipeline\ProcessTaskPipelineJob;
use App\Models\EventObject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * EOB-05: the embedded task section trusted its parent page for ownership.
 */
class TaskExecutionSectionAuthorizationTest extends TestCase
{
    use EntityFixtures;
    use RefreshDatabase;

    #[Test]
    public function it_refuses_every_kind_of_record_another_user_owns(): void
    {
        $bob = User::factory()->create();
        [$integration, $event, $block] = $this->ownedGraph($bob);
        $object = EventObject::factory()->create(['user_id' => $bob->id]);

        $this->actingAs(User::factory()->create());

        foreach ([$integration, $event, $block, $object] as $model) {
            Volt::test('task-execution-section', ['model' => $model])->assertForbidden();
        }
    }

    #[Test]
    public function the_owner_can_view_and_rerun_tasks(): void
    {
        Queue::fake();
        $alice = User::factory()->create();
        [, $event] = $this->ownedGraph($alice);

        $this->actingAs($alice);

        Volt::test('task-execution-section', ['model' => $event])
            ->assertOk()
            ->call('rerunAllTasks')
            ->assertDispatched('tasks-rerun-initiated');

        Queue::assertPushed(ProcessTaskPipelineJob::class);
    }

    #[Test]
    public function the_unused_tag_manager_refuses_another_users_record(): void
    {
        $bob = User::factory()->create();
        [, $event] = $this->ownedGraph($bob);

        $this->actingAs(User::factory()->create());

        Volt::test('tag-manager', ['modelClass' => $event::class, 'modelId' => $event->id])->assertForbidden();
    }
}
