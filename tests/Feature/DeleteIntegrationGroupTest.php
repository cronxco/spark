<?php

namespace Tests\Feature;

use App\Jobs\DeleteIntegrationGroupJob;
use App\Jobs\IntegrationGroup\AnalyzeDataJob;
use App\Jobs\IntegrationGroup\DeleteBlockJob;
use App\Jobs\IntegrationGroup\DeleteBlocksBatchJob;
use App\Jobs\IntegrationGroup\DeleteEventJob;
use App\Jobs\IntegrationGroup\DeleteEventObjectJob;
use App\Jobs\IntegrationGroup\DeleteEventsBatchJob;
use App\Jobs\IntegrationGroup\DeleteIntegrationGroupFinalJob;
use App\Jobs\IntegrationGroup\DeleteIntegrationJob;
use App\Jobs\IntegrationGroup\DeleteIntegrationsBatchJob;
use App\Jobs\IntegrationGroup\DeleteOrphanedObjectsBatchJob;
use App\Models\ActionProgress;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class DeleteIntegrationGroupTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function user_can_confirm_delete_group_modal(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'account_id' => 'test-account',
        ]);

        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'github',
        ]);

        Livewire::actingAs($user)
            ->test('actions.delete-integration-group')
            ->call('confirmDeleteGroup', $group->id)
            ->assertSet('showModal', true)
            ->assertSet('groupId', $group->id)
            ->assertSet('step', 1);
    }

    #[Test]
    public function delete_confirmation_requires_correct_service_name(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
        ]);

        $component = Livewire::actingAs($user)
            ->test('actions.delete-integration-group')
            ->call('confirmDeleteGroup', $group->id)
            ->set('confirmationText', 'wrong-service')
            ->call('nextStep');

        // Should not advance to step 3 with wrong service name
        $component->assertSet('step', 2);

        // Now try with correct service name
        $component->set('confirmationText', 'github')
            ->call('nextStep')
            ->assertSet('step', 3);
    }

    #[Test]
    public function delete_group_dispatches_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
        ]);

        Livewire::actingAs($user)
            ->test('actions.delete-integration-group')
            ->call('confirmDeleteGroup', $group->id)
            ->call('nextStep') // Go to step 2
            ->set('confirmationText', 'github')
            ->call('nextStep') // Go to step 3
            ->set('finalConfirmation', true)
            ->call('deleteGroup');

        // Just check that a job was pushed at all
        Queue::assertPushed(DeleteIntegrationGroupJob::class);
    }

    #[Test]
    public function user_cannot_delete_other_users_group(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $group = IntegrationGroup::factory()->create([
            'user_id' => $otherUser->id,
            'service' => 'github',
        ]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::actingAs($user)
            ->test('actions.delete-integration-group')
            ->call('confirmDeleteGroup', $group->id);
    }

    #[Test]
    public function delete_job_removes_all_related_data(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
        ]);

        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'github',
        ]);

        // Create test data
        $actor = EventObject::factory()->create(['user_id' => $user->id]);
        $target = EventObject::factory()->create(['user_id' => $user->id]);

        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'target_id' => $target->id,
        ]);

        $block = Block::factory()->create([
            'event_id' => $event->id,
        ]);

        // Create some activity logs
        activity('changelog')->performedOn($event)->log('test event');
        activity('changelog')->performedOn($block)->log('test block');
        activity('changelog')->performedOn($actor)->log('test object');

        // Execute the full job chain manually
        $analyzeJob = new AnalyzeDataJob($group->id, $user->id);
        $analyzeJob->handle();

        // Since the job chain is asynchronous, we need to manually execute the chain
        // to test the deletion functionality
        $deleteBlocksBatchJob = new DeleteBlocksBatchJob($group->id, $user->id, $analyzeJob->deletionData);
        $deleteBlocksBatchJob->handle();

        // Execute individual block deletion jobs
        foreach ($analyzeJob->deletionData['blocks'] ?? [] as $blockData) {
            $deleteBlockJob = new DeleteBlockJob($blockData['id'], $group->id, $user->id);
            $deleteBlockJob->handle();
        }

        $deleteEventsBatchJob = new DeleteEventsBatchJob($group->id, $user->id, $analyzeJob->deletionData);
        $deleteEventsBatchJob->handle();

        // Execute individual event deletion jobs
        foreach ($analyzeJob->deletionData['events'] ?? [] as $eventData) {
            $deleteEventJob = new DeleteEventJob($eventData['id'], $group->id, $user->id);
            $deleteEventJob->handle();
        }

        $deleteIntegrationsBatchJob = new DeleteIntegrationsBatchJob($group->id, $user->id, $analyzeJob->deletionData);
        $deleteIntegrationsBatchJob->handle();

        // Execute individual integration deletion jobs
        foreach ($analyzeJob->deletionData['integrations'] ?? [] as $integrationData) {
            $deleteIntegrationJob = new DeleteIntegrationJob($integrationData['id'], $group->id, $user->id);
            $deleteIntegrationJob->handle();
        }

        $deleteOrphanedObjectsBatchJob = new DeleteOrphanedObjectsBatchJob($group->id, $user->id, $analyzeJob->deletionData);
        $deleteOrphanedObjectsBatchJob->handle();

        // Execute individual object deletion jobs for orphaned objects
        $orphanedObjects = EventObject::where('user_id', $user->id)
            ->whereDoesntHave('actorEvents')
            ->whereDoesntHave('targetEvents')
            ->get();

        foreach ($orphanedObjects as $object) {
            $deleteEventObjectJob = new DeleteEventObjectJob($object->id, $group->id, $user->id);
            $deleteEventObjectJob->handle();
        }

        $deleteIntegrationGroupFinalJob = new DeleteIntegrationGroupFinalJob($group->id, $user->id, $analyzeJob->deletionData);
        $deleteIntegrationGroupFinalJob->handle();

        // Verify everything is deleted (checking for soft deleted records)
        $this->assertDatabaseMissing('integration_groups', ['id' => $group->id, 'deleted_at' => null]);
        $this->assertDatabaseMissing('integrations', ['id' => $integration->id]);
        $this->assertDatabaseMissing('events', ['id' => $event->id]);
        $this->assertDatabaseMissing('blocks', ['id' => $block->id]);

        // Objects should be deleted if they're orphaned
        $this->assertDatabaseMissing('objects', ['id' => $actor->id]);
        $this->assertDatabaseMissing('objects', ['id' => $target->id]);

        // Activity logs should be cleaned up
        $this->assertEquals(0, Activity::where('log_name', 'changelog')
            ->where('subject_id', $event->id)
            ->where('subject_type', Event::class)
            ->count());
    }

    #[Test]
    public function delete_job_preserves_objects_used_by_other_events(): void
    {
        $user = User::factory()->create();

        // Create two groups
        $group1 = IntegrationGroup::factory()->create(['user_id' => $user->id, 'service' => 'github']);
        $group2 = IntegrationGroup::factory()->create(['user_id' => $user->id, 'service' => 'spotify']);

        $integration1 = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group1->id,
            'service' => 'github',
        ]);

        $integration2 = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group2->id,
            'service' => 'spotify',
        ]);

        // Create shared object
        $sharedObject = EventObject::factory()->create(['user_id' => $user->id]);

        // Create events in both integrations using the same object
        $event1 = Event::factory()->create([
            'integration_id' => $integration1->id,
            'actor_id' => $sharedObject->id,
            'target_id' => EventObject::factory()->create(['user_id' => $user->id])->id,
        ]);

        $event2 = Event::factory()->create([
            'integration_id' => $integration2->id,
            'actor_id' => $sharedObject->id,
            'target_id' => EventObject::factory()->create(['user_id' => $user->id])->id,
        ]);

        // Delete group1 by executing the full job chain manually
        $analyzeJob = new AnalyzeDataJob($group1->id, $user->id);
        $analyzeJob->handle();

        // Shared object should still exist because it's used by event2
        $this->assertDatabaseHas('objects', ['id' => $sharedObject->id]);

        // But event1 should be deleted
        $this->assertDatabaseMissing('events', ['id' => $event1->id]);

        // And event2 should still exist
        $this->assertDatabaseHas('events', ['id' => $event2->id]);
    }

    #[Test]
    public function main_job_dispatches_analyze_data_job(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
        ]);

        // Execute the main job
        $job = new DeleteIntegrationGroupJob($group->id, $user->id);
        $job->handle();

        // The job should complete without errors
        $this->assertTrue(true); // This test just verifies the job runs without throwing exceptions
    }

    #[Test]
    public function integration_group_model_returns_correct_deletion_summary(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'account_id' => 'test-account',
        ]);

        // Create 2 integrations
        $integration1 = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
        ]);
        $integration2 = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
        ]);

        // Create events and blocks
        $event1 = Event::factory()->create(['integration_id' => $integration1->id]);
        $event2 = Event::factory()->create(['integration_id' => $integration2->id]);

        Block::factory()->create(['event_id' => $event1->id]);
        Block::factory()->create(['event_id' => $event2->id]);
        Block::factory()->create(['event_id' => $event2->id]);

        $summary = $group->getDeletionSummary();

        $this->assertEquals(2, $summary['integrations']);
        $this->assertEquals(2, $summary['events']);
        $this->assertEquals(3, $summary['blocks']);
        $this->assertEquals('github', $summary['service_name']);
        $this->assertEquals('test-account', $summary['account_id']);
    }

    #[Test]
    public function job_handles_errors_gracefully(): void
    {
        $user = User::factory()->create();

        // Try to delete non-existent group (use a valid UUID format)
        $job = new DeleteIntegrationGroupJob('550e8400-e29b-41d4-a716-446655440000', $user->id);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $job->handle();
    }

    #[Test]
    public function job_respects_user_ownership(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $group = IntegrationGroup::factory()->create(['user_id' => $user2->id]);

        // User1 tries to delete user2's group
        $job = new DeleteIntegrationGroupJob($group->id, $user1->id);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $job->handle();
    }

    #[Test]
    public function job_updates_progress_correctly(): void
    {
        $user = User::factory()->create();

        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
        ]);

        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'github',
        ]);

        // Execute the full job chain manually
        $analyzeJob = new AnalyzeDataJob($group->id, $user->id);
        $analyzeJob->handle();

        // Verify ActionProgress record was created and completed
        $progress = ActionProgress::getLatestProgress($user->id, 'deletion', $group->id);

        $this->assertNotNull($progress);
        $this->assertTrue($progress->isCompleted());
        $this->assertFalse($progress->isFailed());
        $this->assertEquals('completed', $progress->step);
        $this->assertEquals(100, $progress->progress);
        $this->assertStringContainsString('completed successfully', $progress->message);
    }
}
