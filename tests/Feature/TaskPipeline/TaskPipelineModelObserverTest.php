<?php

namespace Tests\Feature\TaskPipeline;

use App\Jobs\TaskPipeline\ProcessTaskPipelineJob;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TaskPipelineModelObserverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.enable_task_pipeline' => false]);
    }

    #[Test]
    public function create_flows_dispatch_pipeline_for_events_blocks_and_objects(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $actor = EventObject::factory()->create(['user_id' => $user->id]);
        $target = EventObject::factory()->create(['user_id' => $user->id]);

        config(['app.enable_task_pipeline' => true]);
        Queue::fake([ProcessTaskPipelineJob::class]);

        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'target_id' => $target->id,
        ]);
        $block = Block::factory()->create(['event_id' => $event->id]);
        $object = EventObject::factory()->create(['user_id' => $user->id]);

        Queue::assertPushed(
            ProcessTaskPipelineJob::class,
            fn (ProcessTaskPipelineJob $job) => $job->model->is($event)
                && $job->trigger === 'created'
                && $job->force === false
        );

        Queue::assertPushed(
            ProcessTaskPipelineJob::class,
            fn (ProcessTaskPipelineJob $job) => $job->model->is($block)
                && $job->trigger === 'created'
                && $job->force === false
        );

        Queue::assertPushed(
            ProcessTaskPipelineJob::class,
            fn (ProcessTaskPipelineJob $job) => $job->model->is($object)
                && $job->trigger === 'created'
                && $job->force === false
        );
    }

    #[Test]
    public function event_update_dispatches_for_changed_derived_data_fields(): void
    {
        $event = Event::factory()->create(['action' => 'paid']);

        config(['app.enable_task_pipeline' => true]);
        Queue::fake([ProcessTaskPipelineJob::class]);

        $event->update(['action' => 'refunded']);

        Queue::assertPushed(
            ProcessTaskPipelineJob::class,
            fn (ProcessTaskPipelineJob $job) => $job->model->is($event)
                && $job->trigger === 'updated'
                && $job->force === true
                && $job->changedFields === ['action']
        );
    }

    #[Test]
    public function event_update_ignores_fields_that_do_not_change_derived_data(): void
    {
        $event = Event::factory()->create(['event_metadata' => []]);

        config(['app.enable_task_pipeline' => true]);
        Queue::fake([ProcessTaskPipelineJob::class]);

        $event->update(['event_metadata' => ['task_executions' => []]]);

        Queue::assertNotPushed(ProcessTaskPipelineJob::class);
    }

    #[Test]
    public function block_update_dispatches_for_block_and_refreshes_parent_event_summary_embedding(): void
    {
        $event = Event::factory()->create();
        $block = Block::factory()->create([
            'event_id' => $event->id,
            'block_type' => 'event_summary',
            'title' => 'Original summary',
        ]);

        config(['app.enable_task_pipeline' => true]);
        Queue::fake([ProcessTaskPipelineJob::class]);

        $block->update(['title' => 'Updated summary']);

        Queue::assertPushed(
            ProcessTaskPipelineJob::class,
            fn (ProcessTaskPipelineJob $job) => $job->model->is($block)
                && $job->trigger === 'updated'
                && $job->force === true
                && $job->changedFields === ['title']
        );

        Queue::assertPushed(
            ProcessTaskPipelineJob::class,
            fn (ProcessTaskPipelineJob $job) => $job->model->is($event)
                && $job->trigger === 'updated'
                && $job->taskFilter === ['generate_embedding']
                && $job->force === true
                && $job->changedFields === ['blocks.title']
        );
    }

    #[Test]
    public function object_update_dispatches_for_changed_derived_data_fields(): void
    {
        $object = EventObject::factory()->create(['title' => 'Original title']);

        config(['app.enable_task_pipeline' => true]);
        Queue::fake([ProcessTaskPipelineJob::class]);

        $object->update(['title' => 'Updated title']);

        Queue::assertPushed(
            ProcessTaskPipelineJob::class,
            fn (ProcessTaskPipelineJob $job) => $job->model->is($object)
                && $job->trigger === 'updated'
                && $job->force === true
                && $job->changedFields === ['title']
        );
    }
}
