<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\MetricStatistic;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HealthDashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    /** @var array<string, Integration> */
    protected array $integrations = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);
        config(['app.timezone' => 'Europe/London']);
        Carbon::setTestNow('2026-05-18 19:30:00');

        $this->user = User::factory()->create();
        foreach (['oura', 'apple_health', 'hevy', 'flint'] as $service) {
            $this->integrations[$service] = $this->integration($service);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function requires_authentication_and_accepts_ios_read_tokens(): void
    {
        $this->getJson('/api/v1/mobile/health/dashboard')->assertStatus(401);

        Sanctum::actingAs($this->user, ['ios:write']);
        $this->getJson('/api/v1/mobile/health/dashboard')->assertStatus(403);

        Sanctum::actingAs($this->user, ['ios:read']);
        $this->getJson('/api/v1/mobile/health/dashboard')->assertOk();
    }

    #[Test]
    public function validates_date_and_range_like_briefing(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/health/dashboard?date[]=2026-05-18')->assertStatus(422);
        $this->getJson('/api/v1/mobile/health/dashboard?date=2026-5-18')->assertStatus(422);
        $this->getJson('/api/v1/mobile/health/dashboard?range=14d')->assertStatus(422);
        $this->getJson('/api/v1/mobile/health/dashboard?range[]=7d')->assertStatus(422);
    }

    #[Test]
    public function empty_data_returns_stable_shape(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/health/dashboard?date=2026-05-18')
            ->assertOk()
            ->assertJsonPath('date', '2026-05-18')
            ->assertJsonPath('timezone', 'Europe/London')
            ->assertJsonPath('range', '7d')
            ->assertJsonPath('sync_status', [])
            ->assertJsonPath('fitness.today.workout_count', 0)
            ->assertJsonPath('fitness.workouts', [])
            ->assertJsonPath('body_metrics', [])
            ->assertJsonPath('trends', [])
            ->assertJsonPath('insights', []);
    }

    #[Test]
    public function oura_readiness_and_sleep_appear_in_hero_and_body_metrics(): void
    {
        $this->stat('oura', 'had_readiness_score', 'percent', mean: 80, lower: 70, upper: 90);
        $this->stat('oura', 'had_sleep_score', 'percent', mean: 82, lower: 72, upper: 92);

        $readiness = $this->event('oura', 'had_readiness_score', 58, 'percent', '2026-05-18 08:00:00');
        Block::factory()->create([
            'event_id' => $readiness->id,
            'block_type' => 'contributor',
            'title' => 'Resting Heart Rate',
            'value' => -13,
            'value_multiplier' => 1,
            'value_unit' => 'percent',
        ]);
        $this->event('oura', 'had_sleep_score', 78, 'percent', '2026-05-18 07:00:00');

        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/health/dashboard?date=2026-05-18')
            ->assertOk()
            ->assertJsonPath('hero.primary_event_id', $readiness->id)
            ->assertJsonPath('hero.kind', 'readiness')
            ->assertJsonPath('hero.factors.0.label', 'Resting Heart Rate')
            ->assertJsonPath('body_metrics.0.label', 'Readiness')
            ->assertJsonPath('body_metrics.1.label', 'Sleep Score');
    }

    #[Test]
    public function apple_health_workouts_include_dashboard_workout_fields(): void
    {
        $workout = $this->event('apple_health', 'did_workout', 135.695, 'kcal', '2026-05-18 10:22:54', [
            'end' => '2026-05-18T10:37:01+00:00',
            'duration_seconds' => 846.921,
            'distance' => 1.976,
            'distance_unit' => 'km',
            'intensity' => 9.498,
            'intensity_unit' => 'kcal/hr·kg',
            'route_summary' => ['total_points' => 12],
        ], targetTitle: 'Run');
        $this->block($workout, 'duration', 846.921, 's');
        $this->block($workout, 'distance', 1.976, 'km');
        $this->block($workout, 'energy', 135.695, 'kcal');
        $this->block($workout, 'intensity', 9.498, 'kcal/hr·kg');

        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/health/dashboard?date=2026-05-18')
            ->assertOk()
            ->assertJsonPath('fitness.workouts.0.event_id', $workout->id)
            ->assertJsonPath('fitness.workouts.0.source', 'apple_health')
            ->assertJsonPath('fitness.workouts.0.duration_seconds', 846.921)
            ->assertJsonPath('fitness.workouts.0.energy_kcal', 135.695)
            ->assertJsonPath('fitness.workouts.0.distance.value', 1.976)
            ->assertJsonPath('fitness.workouts.0.intensity.value', 9.498)
            ->assertJsonPath('fitness.workouts.0.route_available', true);
    }

    #[Test]
    public function hevy_workout_includes_total_volume_and_exercise_summaries(): void
    {
        $workout = $this->event('hevy', 'completed_workout', 5330, 'kg', '2026-05-18 09:37:49', [
            'duration_seconds' => 1800,
        ], targetTitle: 'Legs');
        Block::factory()->create([
            'event_id' => $workout->id,
            'block_type' => 'exercise_summary',
            'title' => 'Leg Press (Machine) - Total Volume',
            'metadata' => ['exercise_name' => 'Leg Press (Machine)', 'sets_count' => 4, 'unit' => 'kg'],
            'value' => 4200,
            'value_multiplier' => 1,
            'value_unit' => 'kg',
        ]);

        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/health/dashboard?date=2026-05-18')
            ->assertOk()
            ->assertJsonPath('fitness.workouts.0.source', 'hevy')
            ->assertJsonPath('fitness.workouts.0.volume.value', 5330)
            ->assertJsonPath('fitness.workouts.0.exercises.0.name', 'Leg Press (Machine)')
            ->assertJsonPath('fitness.workouts.0.exercises.0.sets', 4)
            ->assertJsonPath('fitness.workouts.0.exercises.0.volume.value', 4200);
    }

    #[Test]
    public function duplicate_oura_workouts_are_deduped_with_apple_preferred(): void
    {
        $apple = $this->event('apple_health', 'did_workout', 100, 'kcal', '2026-05-18 10:00:00', [
            'duration_seconds' => 900,
            'end' => '2026-05-18T10:15:00+00:00',
        ], targetTitle: 'Run');
        $this->event('oura', 'did_workout', 108, 'kcal', '2026-05-18 10:08:00', [
            'duration_seconds' => 880,
            'end_datetime' => '2026-05-18T10:22:40+00:00',
        ], targetTitle: 'Run');

        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/health/dashboard?date=2026-05-18')
            ->assertOk()
            ->assertJsonCount(1, 'fitness.workouts')
            ->assertJsonPath('fitness.workouts.0.event_id', $apple->id)
            ->assertJsonPath('fitness.workouts.0.source', 'apple_health');
    }

    #[Test]
    public function trend_payloads_are_included_for_configured_dashboard_metrics(): void
    {
        $this->stat('apple_health', 'had_step_count', 'steps', mean: 8000, lower: 6000, upper: 10000);
        $this->event('apple_health', 'had_step_count', 7411, 'steps', '2026-05-18 18:00:00');

        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/health/dashboard?date=2026-05-18&range=7d')
            ->assertOk()
            ->assertJsonPath('trends.0.metric', 'apple_health.had_step_count.steps')
            ->assertJsonPath('trends.0.label', 'Steps')
            ->assertJsonPath('trends.0.daily_values.0.value', 7411);
    }

    #[Test]
    public function flint_health_insights_are_included_and_capped(): void
    {
        $flint = $this->event('flint', 'had_summary', null, null, '2026-05-18 12:00:00');

        foreach (range(1, 4) as $i) {
            Block::factory()->create([
                'event_id' => $flint->id,
                'block_type' => $i === 4 ? 'flint_insight' : 'flint_health_insight',
                'title' => "Insight {$i}",
                'metadata' => ['content' => "Health insight {$i}"],
                'time' => Carbon::parse("2026-05-18 12:0{$i}:00"),
            ]);
        }

        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/health/dashboard?date=2026-05-18')
            ->assertOk()
            ->assertJsonCount(3, 'insights')
            ->assertJsonPath('insights.0.title', 'Insight 1')
            ->assertJsonPath('insights.0.content', 'Health insight 1');
    }

    private function integration(string $service): Integration
    {
        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => $service,
        ]);

        return Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => $service,
            'instance_type' => $service === 'flint' ? 'digest' : null,
        ]);
    }

    private function stat(string $service, string $action, string $unit, float $mean, float $lower, float $upper): MetricStatistic
    {
        return MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => $service,
            'action' => $action,
            'value_unit' => $unit,
            'mean_value' => $mean,
            'stddev_value' => 5,
            'normal_lower_bound' => $lower,
            'normal_upper_bound' => $upper,
            'event_count' => 30,
        ]);
    }

    private function event(
        string $service,
        string $action,
        float|int|null $value,
        ?string $unit,
        string $time,
        array $metadata = [],
        ?string $targetTitle = null,
    ): Event {
        $actor = EventObject::factory()->create(['user_id' => $this->user->id]);
        $target = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'title' => $targetTitle ?? ucfirst(str_replace('_', ' ', $action)),
        ]);

        [$encodedValue, $multiplier] = $this->encodeNumber($value);

        return Event::factory()->create([
            'integration_id' => $this->integrations[$service]->id,
            'actor_id' => $actor->id,
            'target_id' => $target->id,
            'service' => $service,
            'domain' => 'health',
            'action' => $action,
            'value' => $encodedValue,
            'value_multiplier' => $multiplier,
            'value_unit' => $unit,
            'event_metadata' => $metadata,
            'time' => Carbon::parse($time),
        ]);
    }

    private function block(Event $event, string $type, float $value, string $unit): Block
    {
        [$encodedValue, $multiplier] = $this->encodeNumber($value);

        return Block::factory()->create([
            'event_id' => $event->id,
            'block_type' => $type,
            'title' => ucfirst($type),
            'value' => $encodedValue,
            'value_multiplier' => $multiplier,
            'value_unit' => $unit,
            'time' => $event->time,
        ]);
    }

    /**
     * @return array{0: int|null, 1: int}
     */
    private function encodeNumber(float|int|null $value): array
    {
        if ($value === null) {
            return [null, 1];
        }

        if ((float) $value === floor((float) $value)) {
            return [(int) $value, 1];
        }

        return [(int) round(((float) $value) * 1000), 1000];
    }
}
