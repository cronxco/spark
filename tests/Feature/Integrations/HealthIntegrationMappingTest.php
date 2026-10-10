<?php

namespace Tests\Feature\Integrations;

use App\Integrations\AppleHealth\AppleHealthPlugin;
use App\Integrations\Hevy\HevyPlugin;
use App\Integrations\Oura\OuraPlugin;
use App\Jobs\Data\Oura\OuraSleepRecordsData;
use App\Jobs\Data\Oura\OuraWorkoutsData;
use App\Jobs\Data\Oura\OuraVO2MaxData;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class HealthIntegrationMappingTest extends TestCase
{
    #[Test]
    public function apple_workout_offsets_preserve_the_instant_and_raw_provenance(): void
    {
        $integration = new Integration(['id' => 'test', 'service' => 'apple_health']);
        $workout = [
            'id' => 'walk',
            'name' => 'Walk',
            'start' => '2026-10-09 18:23:00 +0100',
            'end' => '2026-10-09 18:33:00 +0100',
            'source' => ['identifier' => 'com.ouraring.oura'],
            'activities' => [['name' => 'walking']],
        ];

        $mapped = (new AppleHealthPlugin)->mapWorkoutToEvent($workout, $integration);

        $this->assertSame('2026-10-09T17:23:00+00:00', $mapped['time']);
        $this->assertSame('2026-10-09T17:33:00+00:00', $mapped['event_metadata']['end']);
        $this->assertSame($workout, $mapped['event_metadata']['raw']);
        $this->assertSame($workout['source'], $mapped['event_metadata']['source']);
        $this->assertSame($mapped['time'], $mapped['target']['time']);
    }

    #[Test]
    public function oura_workout_uses_v2_timestamps_and_keeps_categorical_fields(): void
    {
        Queue::fake();
        $integration = Integration::factory()->create(['service' => 'oura']);
        $actor = EventObject::factory()->create(['user_id' => $integration->user_id]);
        $plugin = Mockery::mock(OuraPlugin::class)->makePartial();
        $plugin->shouldReceive('ensureUserProfile')->once()->andReturn($actor);
        $job = new OuraWorkoutsData($integration, []);
        $method = new ReflectionMethod($job, 'createEnhancedWorkoutEvent');
        $method->invoke($job, $plugin, [
            'id' => 'workout-id',
            'activity' => 'walking',
            'start_datetime' => '2026-10-09T18:23:00+01:00',
            'end_datetime' => '2026-10-09T18:33:00+01:00',
            'calories' => 84.652,
            'intensity' => 'moderate',
            'source' => 'confirmed',
            'distance' => 700,
            'label' => 'Evening walk',
        ]);

        $event = Event::where('integration_id', $integration->id)->firstOrFail();
        $this->assertSame('2026-10-09 17:23:00', $event->time->format('Y-m-d H:i:s'));
        $this->assertSame(600, $event->event_metadata['duration_seconds']);
        $this->assertSame('moderate', $event->event_metadata['intensity']);
        $this->assertSame('confirmed', $event->event_metadata['source']);
        $this->assertSame('Evening walk', $event->event_metadata['label']);
        $this->assertTrue($event->blocks()->where('title', 'Duration')->exists());
        $this->assertTrue($event->blocks()->where('title', 'Distance')->exists());
        $this->assertFalse($event->blocks()->where('title', 'Workout Intensity')->exists());
    }

    #[Test]
    public function sleep_record_keeps_raw_data_on_event_and_static_target(): void
    {
        $integration = new Integration(['id' => 'test', 'service' => 'oura']);
        $job = new OuraSleepRecordsData($integration, []);
        $method = new ReflectionMethod($job, 'createSleepRecordEvent');
        $item = [
            'id' => 'sleep-id',
            'bedtime_start' => '2026-10-09T22:04:00+01:00',
            'bedtime_end' => '2026-10-10T06:00:00+01:00',
            'type' => 'long_sleep',
            'total_sleep_duration' => 25000,
        ];
        $mapped = $method->invoke($job, $item, new OuraPlugin);

        $this->assertSame('2026-10-09 21:04:00', $mapped['time']->format('Y-m-d H:i:s'));
        $this->assertSame('long_sleep', $mapped['event_metadata']['type']);
        $this->assertSame($item, $mapped['event_metadata']['raw']);
        $this->assertArrayNotHasKey('time', $mapped['target']);
        $this->assertArrayNotHasKey('metadata', $mapped['target']);
    }

    #[Test]
    public function vo2_measurement_preserves_offset_timestamp(): void
    {
        Queue::fake();
        $integration = Integration::factory()->create(['service' => 'oura']);
        $object = EventObject::factory()->create(['user_id' => $integration->user_id]);
        $plugin = Mockery::mock(OuraPlugin::class)->makePartial();
        $plugin->shouldReceive('ensureUserProfile')->once()->andReturn($object);
        $plugin->shouldReceive('getStaticMetricObject')->once()->andReturn($object);
        $job = new OuraVO2MaxData($integration, []);
        (new ReflectionMethod($job, 'createVO2MaxEvent'))->invoke($job, [
            'id' => 'vo2-id',
            'day' => '2026-10-09',
            'timestamp' => '2026-10-09T20:00:00+01:00',
            'vo2_max' => 40,
        ], $plugin);

        $event = Event::where('integration_id', $integration->id)->firstOrFail();
        $this->assertSame('2026-10-09 19:00:00', $event->time->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function hevy_duration_is_positive_for_a_completed_workout(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response([])]);
        $integration = Integration::factory()->create(['service' => 'hevy']);
        (new HevyPlugin)->createWorkoutEvent($integration, [
            'id' => 'hevy-id',
            'title' => 'Lift',
            'start_time' => '2026-09-07T05:38:23+00:00',
            'end_time' => '2026-09-07T06:23:26+00:00',
            'exercises' => [],
        ]);

        $event = Event::where('integration_id', $integration->id)->firstOrFail();
        $this->assertSame(2703, $event->event_metadata['duration_seconds']);
    }

    #[Test]
    public function winter_workout_times_stay_utc_and_reversed_duration_is_clamped(): void
    {
        Queue::fake();
        $integration = Integration::factory()->create(['service' => 'oura']);
        $actor = EventObject::factory()->create(['user_id' => $integration->user_id]);
        $plugin = Mockery::mock(OuraPlugin::class)->makePartial();
        $plugin->shouldReceive('ensureUserProfile')->once()->andReturn($actor);
        $job = new OuraWorkoutsData($integration, []);
        (new ReflectionMethod($job, 'createEnhancedWorkoutEvent'))->invoke($job, $plugin, [
            'id' => 'winter-id',
            'activity' => 'walking',
            'start_datetime' => '2026-01-09T18:23:00Z',
            'end_datetime' => '2026-01-09T18:13:00Z',
            'calories' => 20,
        ]);

        $event = Event::where('integration_id', $integration->id)->firstOrFail();
        $this->assertSame('2026-01-09 18:23:00', $event->time->format('Y-m-d H:i:s'));
        $this->assertSame(0, $event->event_metadata['duration_seconds']);
        $this->assertFalse($event->blocks()->where('title', 'Duration')->exists());
    }

    #[Test]
    public function hevy_workout_action_declares_weight_volume(): void
    {
        $this->assertSame('kg', HevyPlugin::getActionTypes()['completed_workout']['value_unit']);
    }
}
