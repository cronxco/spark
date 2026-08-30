<?php

namespace Tests\Feature\Fetch;

use App\Jobs\Fetch\CheckCookieExpiryJob;
use App\Models\IntegrationGroup;
use App\Models\TaskExecution;
use App\Models\User;
use App\Services\TaskPipeline\TaskExecutionStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CheckCookieExpiryTaskExecutionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function fresh_notification_records_a_successful_task_execution_anchored_to_the_group(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'fetch',
            'auth_metadata' => [
                'domains' => [
                    'example.com' => ['expires_at' => now()->addDay()->toIso8601String()],
                ],
            ],
        ]);

        (new CheckCookieExpiryJob)->handle(app(TaskExecutionStore::class));

        $execution = TaskExecution::where('entity_type', 'integration_group')
            ->where('entity_id', $group->id)
            ->where('task_key', 'check_cookie_expiry_example_com')
            ->firstOrFail();

        $this->assertSame('success', $execution->status);
        $this->assertTrue($execution->last_success['notification_sent']);
        $this->assertSame($user->id, $execution->user_id);
    }

    #[Test]
    public function same_day_dedupe_still_records_success_without_a_notification(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'fetch',
            'auth_metadata' => [
                'domains' => [
                    'example.com' => ['expires_at' => now()->addDay()->toIso8601String()],
                ],
                'cookie_notifications_sent' => [
                    'example.com' => ['1day' => now()->toIso8601String()],
                ],
            ],
        ]);

        (new CheckCookieExpiryJob)->handle(app(TaskExecutionStore::class));

        $execution = TaskExecution::where('entity_type', 'integration_group')
            ->where('entity_id', $group->id)
            ->where('task_key', 'check_cookie_expiry_example_com')
            ->firstOrFail();

        $this->assertSame('success', $execution->status);
        $this->assertFalse($execution->last_success['notification_sent']);
    }

    #[Test]
    public function invalid_expiry_date_records_a_failed_task_execution(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'fetch',
            'auth_metadata' => [
                'domains' => [
                    'example.com' => ['expires_at' => 'not-a-real-date'],
                ],
            ],
        ]);

        (new CheckCookieExpiryJob)->handle(app(TaskExecutionStore::class));

        $execution = TaskExecution::where('entity_type', 'integration_group')
            ->where('entity_id', $group->id)
            ->where('task_key', 'check_cookie_expiry_example_com')
            ->firstOrFail();

        $this->assertSame('failed', $execution->status);
        $this->assertNotEmpty($execution->error);
    }
}
