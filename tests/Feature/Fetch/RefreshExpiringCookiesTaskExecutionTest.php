<?php

namespace Tests\Feature\Fetch;

use App\Jobs\Fetch\RefreshExpiringCookies;
use App\Models\EventObject;
use App\Models\IntegrationGroup;
use App\Models\TaskExecution;
use App\Models\User;
use App\Services\TaskPipeline\TaskExecutionStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RefreshExpiringCookiesTaskExecutionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function successful_refresh_records_a_task_execution_anchored_to_the_webpage(): void
    {
        $user = User::factory()->create();
        $group = $this->createExpiringDomain($user);

        Http::fake([
            '*/health' => Http::response(['status' => 'ok', 'connected' => true], 200),
            '*/fetch' => Http::response([
                'html' => '<html></html>',
                'title' => 'Test Page',
                'url' => 'https://example.com',
                'cookies' => [['name' => 'session', 'value' => 'abc']],
                'screenshot' => null,
            ], 200),
        ]);

        (new RefreshExpiringCookies)->handle(app(TaskExecutionStore::class));

        $webpage = EventObject::where('user_id', $user->id)->where('type', 'fetch_webpage')->firstOrFail();

        $execution = TaskExecution::where('entity_type', 'object')
            ->where('entity_id', $webpage->id)
            ->where('task_key', "refresh_expiring_cookies_group_{$group->id}")
            ->firstOrFail();

        $this->assertSame('success', $execution->status);
        $this->assertSame('example.com', $execution->last_success['domain']);
        $this->assertSame(1, $execution->last_success['cookie_count']);
    }

    #[Test]
    public function unavailable_worker_records_a_failed_task_execution(): void
    {
        $user = User::factory()->create();
        $group = $this->createExpiringDomain($user);

        Http::fake([
            '*/health' => Http::response(['status' => 'error'], 500),
        ]);

        (new RefreshExpiringCookies)->handle(app(TaskExecutionStore::class));

        $webpage = EventObject::where('user_id', $user->id)->where('type', 'fetch_webpage')->firstOrFail();

        $execution = TaskExecution::where('entity_type', 'object')
            ->where('entity_id', $webpage->id)
            ->where('task_key', "refresh_expiring_cookies_group_{$group->id}")
            ->firstOrFail();

        $this->assertSame('failed', $execution->status);
        $this->assertNotEmpty($execution->error);
    }

    protected function createExpiringDomain(User $user, string $domain = 'example.com'): IntegrationGroup
    {
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'fetch',
            'auth_metadata' => [
                'domains' => [
                    $domain => [
                        'auto_refresh_enabled' => true,
                        'expires_at' => now()->addDays(2)->toIso8601String(),
                    ],
                ],
            ],
        ]);

        EventObject::create([
            'user_id' => $user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'url' => "https://{$domain}",
            'title' => 'Test Page',
            'metadata' => [
                'enabled' => true,
                'domain' => $domain,
            ],
        ]);

        return $group;
    }
}
