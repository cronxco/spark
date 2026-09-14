<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Jobs\Flint\TriggerFlintRoutineJob;
use App\Models\User;
use App\Services\FlintDigestService;
use App\Services\TaskPipeline\TaskExecutionStore;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FlintRoutineHealthControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function health_distinguishes_accepted_work_from_persisted_output(): void
    {
        Carbon::setTestNow('2026-09-14 12:00:00');
        config([
            'ios.mobile_api_enabled' => true,
            'services.flint_routine.routines.topics.url' => 'https://routine.example.test/topics',
            'services.flint_routine.routines.digest.url' => null,
            'services.flint_routine.routines.reading_list.url' => null,
            'services.flint_routine.routines.news_roundup.url' => null,
        ]);
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $user = User::factory()->create(['settings' => ['flint' => [
            'topics_enabled' => true,
            'morning_digest_enabled' => false,
            'evening_digest_enabled' => false,
            'reading_list_enabled' => false,
            'news_roundup_enabled' => false,
        ]]]);
        $user->setTimezone('Europe/London');
        (new TriggerFlintRoutineJob($user, 'topics', '2026-09-14', 'Europe/London'))
            ->handle(app(FlintDigestService::class), app(TaskExecutionStore::class));
        Sanctum::actingAs($user, ['ios:read']);

        $response = $this->getJson('/api/v1/mobile/flint/routines/health')->assertOk();
        $topics = collect($response->json('data'))->firstWhere('routine', 'topics');

        $this->assertSame('configured', $topics['state']);
        $this->assertSame('accepted', $topics['last_attempt']['status']);
        $this->assertNull($topics['last_persisted_output']);
        $this->assertArrayNotHasKey('error', $topics['last_attempt']);
        $response->assertJsonPath('meta.effective_timezone', 'Europe/London');
    }
}
