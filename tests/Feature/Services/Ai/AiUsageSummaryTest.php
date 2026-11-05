<?php

namespace Tests\Feature\Services\Ai;

use App\Models\Event;
use App\Models\Integration;
use App\Models\User;
use App\Services\Ai\AiUsageSummary;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiUsageSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function it_aggregates_inclusive_local_date_ranges_by_model_and_isolates_users(): void
    {
        Carbon::setTestNow('2026-09-13 12:00:00');
        $user = User::factory()->create(['settings' => ['timezone' => 'Europe/London']]);
        $integration = $this->integration($user);

        $this->usage($integration, '2026-09-13', 'gpt-5', ['total_tokens' => 30, 'request_count' => 1]);
        $this->usage($integration, '2026-09-07', 'gpt-5', ['total_tokens' => 20, 'request_count' => 2]);
        $this->usage($integration, '2026-09-06', 'old-model', ['total_tokens' => 999, 'request_count' => 1]);
        $this->usage($integration, '2026-09-12', 'gpt-5-mini', ['total_tokens' => 70, 'request_count' => 3]);
        $other = User::factory()->create();
        $this->usage($this->integration($other), '2026-09-13', 'private-model', ['total_tokens' => 5000]);

        $rows = app(AiUsageSummary::class)->for($user, 7);

        $this->assertSame(['gpt-5-mini', 'gpt-5'], array_column($rows, 'model'));
        $this->assertSame(50, $rows[1]['total_tokens']);
        $this->assertSame(3, $rows[1]['request_count']);
    }

    #[Test]
    public function flint_scope_uses_only_the_flint_service_breakdown(): void
    {
        Carbon::setTestNow('2026-09-13 12:00:00');
        $user = User::factory()->create(['settings' => ['timezone' => 'UTC']]);
        $integration = $this->integration($user);
        $this->usage($integration, '2026-09-13', 'gpt-5', [
            'request_count' => 4,
            'input_tokens' => 100,
            'output_tokens' => 50,
            'total_tokens' => 150,
            'cached_tokens' => 20,
            'reasoning_tokens' => 10,
            'failure_count' => 2,
            'services' => ['flint' => [
                'request_count' => 1,
                'input_tokens' => 30,
                'output_tokens' => 15,
                'total_tokens' => 45,
                'cached_tokens' => 5,
                'reasoning_tokens' => 3,
                'failure_count' => 1,
            ]],
        ]);

        $row = app(AiUsageSummary::class)->for($user, 1, true)[0];

        $this->assertSame(1, $row['request_count']);
        $this->assertSame(30, $row['input_tokens']);
        $this->assertSame(15, $row['output_tokens']);
        $this->assertSame(45, $row['total_tokens']);
        $this->assertSame(5, $row['cached_tokens']);
        $this->assertSame(3, $row['reasoning_tokens']);
        $this->assertSame(1, $row['failure_count']);
    }

    private function integration(User $user): Integration
    {
        return Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'openai',
            'instance_type' => 'internal',
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function usage(Integration $integration, string $date, string $model, array $metadata): Event
    {
        return Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'openai',
            'action' => 'used_ai',
            'event_metadata' => array_merge([
                'internal' => true,
                'local_date' => $date,
                'model' => $model,
            ], $metadata),
        ]);
    }
}
