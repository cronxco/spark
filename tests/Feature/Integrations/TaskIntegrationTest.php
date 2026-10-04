<?php

namespace Tests\Feature\Integrations;

use App\Jobs\CheckIntegrationUpdates;
use App\Jobs\TaskPipeline\ProcessTaskPipelineJob;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use App\Services\Api\ResourceVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Task is an admin-only integration, and the scheduler runs a Task instance
 * only once its `use_schedule` setting is explicitly switched on.
 */
class TaskIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function switchedOffSchedules(): array
    {
        return [
            'use_schedule missing' => [[]],
            'use_schedule false' => [['use_schedule' => false]],
            'use_schedule "false"' => [['use_schedule' => 'false']],
        ];
    }

    #[Test]
    public function the_scheduler_dispatches_a_task_instance_with_use_schedule_on(): void
    {
        Queue::fake();
        $integration = $this->taskInstance(User::factory()->create(['is_admin' => true]), ['use_schedule' => true]);

        (new CheckIntegrationUpdates)->handle();

        Queue::assertPushed(ProcessTaskPipelineJob::class, fn (ProcessTaskPipelineJob $job): bool => $job->model->is($integration));
    }

    #[Test]
    #[DataProvider('switchedOffSchedules')]
    public function the_scheduler_skips_a_task_instance_until_use_schedule_is_switched_on(array $configuration): void
    {
        Queue::fake();
        $this->taskInstance(User::factory()->create(['is_admin' => true]), $configuration);

        (new CheckIntegrationUpdates)->handle();

        Queue::assertNotPushed(ProcessTaskPipelineJob::class);
    }

    #[Test]
    public function the_scheduler_skips_task_instances_owned_by_non_admins(): void
    {
        Queue::fake();
        $this->taskInstance(User::factory()->create(), ['use_schedule' => true]);

        (new CheckIntegrationUpdates)->handle();

        Queue::assertNotPushed(ProcessTaskPipelineJob::class);
    }

    #[Test]
    public function a_paused_task_instance_is_not_scheduled(): void
    {
        Queue::fake();
        $this->taskInstance(User::factory()->create(['is_admin' => true]), ['use_schedule' => true, 'paused' => true]);

        (new CheckIntegrationUpdates)->handle();

        Queue::assertNotPushed(ProcessTaskPipelineJob::class);
    }

    #[Test]
    public function a_non_admin_does_not_see_task_in_the_add_integration_list(): void
    {
        $component = Livewire::actingAs(User::factory()->create())->test('integrations.index');

        $identifiers = array_column($component->get('plugins'), 'identifier');
        $this->assertNotContains('task', $identifiers);
        $this->assertContains('github', $identifiers);
    }

    #[Test]
    public function an_admin_sees_task_in_the_add_integration_list(): void
    {
        $component = Livewire::actingAs(User::factory()->create(['is_admin' => true]))->test('integrations.index');

        $this->assertContains('task', array_column($component->get('plugins'), 'identifier'));
    }

    #[Test]
    public function a_non_admin_cannot_add_a_task_integration(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('integrations.initialize', ['service' => 'task']))->assertNotFound();
        $this->actingAs($user)->get(route('plugins.show', ['service' => 'task']))->assertNotFound();

        $this->assertFalse(IntegrationGroup::where('user_id', $user->id)->where('service', 'task')->exists());
    }

    #[Test]
    public function a_non_admin_cannot_add_task_instances_to_an_existing_group(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::create(['user_id' => $user->id, 'service' => 'task']);

        $this->actingAs($user)->get(route('integrations.onboarding', $group))->assertNotFound();
        $this->actingAs($user)->post(route('integrations.storeInstances', $group), ['types' => ['task']])->assertNotFound();

        $this->assertFalse(Integration::where('integration_group_id', $group->id)->exists());
    }

    #[Test]
    public function an_admin_can_add_a_task_integration(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('integrations.initialize', ['service' => 'task']))->assertRedirect();
        $this->actingAs($admin)->get(route('plugins.show', ['service' => 'task']))->assertOk();

        $this->assertTrue(IntegrationGroup::where('user_id', $admin->id)->where('service', 'task')->exists());
    }

    #[Test]
    public function a_non_admin_cannot_configure_a_task_through_the_versioned_api(): void
    {
        $user = User::factory()->create();
        $integration = $this->taskInstance($user, []);
        Sanctum::actingAs($user, ['integrations:manage']);

        $this->withHeader('If-Match', app(ResourceVersion::class)->etag($integration))
            ->patchJson("/api/v1/integrations/{$integration->id}/configure", [])
            ->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private function taskInstance(User $user, array $configuration): Integration
    {
        $group = IntegrationGroup::create(['user_id' => $user->id, 'service' => 'task']);

        return Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'task',
            'instance_type' => 'task',
            'configuration' => array_merge([
                'task_mode' => 'artisan',
                'task_command' => 'queue:prune-batches',
                'schedule_times' => ['04:10'],
            ], $configuration),
            'last_successful_update_at' => null,
        ]);
    }
}
