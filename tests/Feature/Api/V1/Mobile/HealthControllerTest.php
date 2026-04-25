<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Jobs\Data\AppleHealth\AppleHealthMetricData;
use App\Jobs\Data\AppleHealth\AppleHealthWorkoutData;
use App\Models\Event;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HealthControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);
        $this->user = User::factory()->create();
    }

    #[Test]
    public function samples_requires_authentication(): void
    {
        $this->postJson('/api/v1/mobile/health/samples', ['samples' => [$this->stepSample('a')]])
            ->assertStatus(401);
    }

    #[Test]
    public function samples_requires_write_ability(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->postJson('/api/v1/mobile/health/samples', ['samples' => [$this->stepSample('a')]])
            ->assertStatus(403);
    }

    #[Test]
    public function samples_rejects_empty_payload(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/health/samples', ['samples' => []])
            ->assertStatus(422);
    }

    #[Test]
    public function samples_accepts_new_samples_and_dispatches_metric_job(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $samples = [];
        for ($i = 0; $i < 100; $i++) {
            $hour = str_pad((string) intdiv($i, 4), 2, '0', STR_PAD_LEFT);
            $minute = str_pad((string) (($i % 4) * 15), 2, '0', STR_PAD_LEFT);
            $samples[] = $this->stepSample('sample-' . $i, '2026-04-19T' . $hour . ':' . $minute . ':00Z');
        }

        $response = $this->postJson('/api/v1/mobile/health/samples', ['samples' => $samples])
            ->assertOk();

        $results = $response->json('results');
        $this->assertCount(100, $results);
        foreach ($results as $row) {
            $this->assertEquals('accepted', $row['status']);
        }

        Queue::assertPushed(AppleHealthMetricData::class);
    }

    #[Test]
    public function samples_reports_duplicates_on_replay(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $sample = $this->stepSample('dup-1');

        // Pre-seed Event so the dedupe branch trips without running jobs.
        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'apple_health',
            'instance_type' => 'metrics',
        ]);

        $sourceId = 'apple_metric_step_count_2026-04-19';

        Event::factory()->create([
            'integration_id' => $integration->id,
            'source_id' => $sourceId,
            'service' => 'apple_health',
            'domain' => 'health',
            'action' => 'had_step_count',
            'time' => '2026-04-19 08:00:00',
        ]);

        $response = $this->postJson('/api/v1/mobile/health/samples', ['samples' => [$sample]])
            ->assertOk();

        $this->assertEquals('duplicate', $response->json('results.0.status'));
    }

    #[Test]
    public function samples_accepts_workout_and_dispatches_workout_job(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $workout = [
            'external_id' => 'workout-1',
            'type' => 'HKWorkoutType',
            'start' => '2026-04-19T08:00:00Z',
            'end' => '2026-04-19T08:30:00Z',
            'value' => 5.2,
            'unit' => 'km',
            'metadata' => ['name' => 'Running', 'duration' => 1800],
        ];

        $this->postJson('/api/v1/mobile/health/samples', ['samples' => [$workout]])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'accepted');

        Queue::assertPushed(AppleHealthWorkoutData::class);
    }

    #[Test]
    public function samples_rejects_unknown_type(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $sample = [
            'external_id' => 'x',
            'type' => 'NotAHealthKitType',
            'start' => '2026-04-19T08:00:00Z',
        ];

        $this->postJson('/api/v1/mobile/health/samples', ['samples' => [$sample]])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'rejected');
    }

    protected function stepSample(string $id, string $date = '2026-04-19T08:00:00Z'): array
    {
        return [
            'external_id' => $id,
            'type' => 'HKQuantityTypeIdentifierStepCount',
            'start' => $date,
            'end' => $date,
            'value' => 1234,
            'unit' => 'count',
            'source' => 'iPhone',
        ];
    }
}
