<?php

namespace Tests\Feature\TaskPipeline;

use App\Models\TaskExecution;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TaskExecutionApiTest extends TestCase
{
    #[Test]
    public function index_is_scoped_to_authenticated_user_and_filterable(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $matching = TaskExecution::factory()->create([
            'user_id' => $user->id,
            'status' => 'failed',
            'task_key' => 'generate_embedding',
            'entity_type' => 'event',
            'queue' => 'tasks',
        ]);
        TaskExecution::factory()->create(['user_id' => $user->id, 'status' => 'success']);
        TaskExecution::factory()->create(['user_id' => $otherUser->id, 'status' => 'failed']);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/task-executions?status=failed&task_key=generate_embedding&per_page=10');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $matching->id)
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function show_does_not_expose_other_users_task_execution(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $execution = TaskExecution::factory()->create(['user_id' => $otherUser->id]);

        Sanctum::actingAs($user);

        $this->getJson("/api/task-executions/{$execution->id}")->assertNotFound();
    }
}
