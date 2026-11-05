<?php

namespace Tests\Feature\Integrations\Oura;

use App\Jobs\Base\BaseProcessingJob;
use App\Jobs\Data\Oura\OuraActivityData;
use App\Jobs\Data\Oura\OuraCardiovascularAgeData;
use App\Jobs\Data\Oura\OuraReadinessData;
use App\Jobs\Data\Oura\OuraResilienceData;
use App\Jobs\Data\Oura\OuraSleepData;
use App\Jobs\Data\Oura\OuraSleepTimeData;
use App\Jobs\Data\Oura\OuraSpo2Data;
use App\Jobs\Data\Oura\OuraStressData;
use App\Jobs\Data\Oura\OuraVO2MaxData;
use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class OuraDailyRevisionTest extends TestCase
{
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'api.ouraring.com/*' => Http::response(['data' => []]),
        ]);

        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'oura',
            'access_token' => 'test-access-token',
        ]);
        $this->integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'oura',
        ]);
    }

    /**
     * @return array<string, array{0: class-string<BaseProcessingJob>, 1: string, 2: array<string, mixed>, 3: array<string, mixed>, 4: int}>
     */
    public static function revisableMetricProvider(): array
    {
        return [
            'stress' => [
                OuraStressData::class,
                'had_stress_score',
                ['day' => '2026-09-26', 'day_summary' => 'normal', 'stress_high' => 600],
                ['day' => '2026-09-26', 'day_summary' => 'stressful', 'stress_high' => 5400],
                3,
            ],
            'resilience' => [
                OuraResilienceData::class,
                'had_resilience_score',
                ['day' => '2026-09-26', 'level' => 'adequate', 'contributors' => ['sleep_recovery' => 40]],
                ['day' => '2026-09-26', 'level' => 'strong', 'contributors' => ['sleep_recovery' => 80]],
                4,
            ],
            'spo2' => [
                OuraSpo2Data::class,
                'had_spo2',
                ['id' => 'spo2-1', 'day' => '2026-09-26', 'spo2_percentage' => ['average' => 94]],
                ['id' => 'spo2-1', 'day' => '2026-09-26', 'spo2_percentage' => ['average' => 97]],
                97,
            ],
            'vo2 max' => [
                OuraVO2MaxData::class,
                'had_vo2_max',
                ['id' => 'vo2-1', 'day' => '2026-09-26', 'vo2_max' => 40],
                ['id' => 'vo2-1', 'day' => '2026-09-26', 'vo2_max' => 42],
                42,
            ],
            'cardiovascular age' => [
                OuraCardiovascularAgeData::class,
                'had_cardiovascular_age',
                ['day' => '2026-09-26', 'vascular_age' => 38],
                ['day' => '2026-09-26', 'vascular_age' => 35],
                35,
            ],
        ];
    }

    #[Test]
    public function revised_sleep_score_updates_existing_event_and_contributors(): void
    {
        $this->runJob(OuraSleepData::class, [[
            'id' => 'sleep-1',
            'day' => '2026-09-26',
            'score' => 54,
            'contributors' => ['total_sleep' => 29, 'deep_sleep' => 55],
        ]]);

        $this->runJob(OuraSleepData::class, [[
            'id' => 'sleep-1',
            'day' => '2026-09-26',
            'score' => 89,
            'contributors' => ['total_sleep' => 100, 'deep_sleep' => 98],
        ]]);

        $events = Event::where('action', 'had_sleep_score')->get();
        $this->assertCount(1, $events);

        $event = $events->first();
        $this->assertSame(89, (int) $event->value);
        $this->assertSame(100, (int) $event->blocks()->where('title', 'Total Sleep')->value('value'));
        $this->assertSame(98, (int) $event->blocks()->where('title', 'Deep Sleep')->value('value'));
        $this->assertSame(2, $event->blocks()->where('block_type', 'contributor')->count());
    }

    #[Test]
    public function revised_activity_score_updates_existing_event_and_metadata(): void
    {
        $this->runJob(OuraActivityData::class, [[
            'day' => '2026-09-26',
            'score' => 40,
            'steps' => 1200,
            'contributors' => ['meet_daily_targets' => 20],
        ]]);

        $this->runJob(OuraActivityData::class, [[
            'day' => '2026-09-26',
            'score' => 78,
            'steps' => 9500,
            'contributors' => ['meet_daily_targets' => 80],
        ]]);

        $events = Event::where('action', 'had_activity_score')->get();
        $this->assertCount(1, $events);
        $this->assertSame(78, (int) $events->first()->value);
        $this->assertSame(9500, $events->first()->event_metadata['steps']);
        $this->assertSame(80, (int) $events->first()->blocks()->where('title', 'Meet Daily Targets')->value('value'));
    }

    #[Test]
    public function revised_readiness_score_updates_existing_event_and_contributors(): void
    {
        $this->runJob(OuraReadinessData::class, [[
            'day' => '2026-09-26',
            'score' => 60,
            'contributors' => ['hrv_balance' => 50],
        ]]);

        $this->runJob(OuraReadinessData::class, [[
            'day' => '2026-09-26',
            'score' => 84,
            'contributors' => ['hrv_balance' => 90],
        ]]);

        $events = Event::where('action', 'had_readiness_score')->get();
        $this->assertCount(1, $events);
        $this->assertSame(84, (int) $events->first()->value);
        $this->assertSame(90, (int) $events->first()->blocks()->where('title', 'Hrv Balance')->value('value'));
    }

    /**
     * @param  class-string<BaseProcessingJob>  $jobClass
     * @param  array<string, mixed>  $initialItem
     * @param  array<string, mixed>  $revisedItem
     */
    #[Test]
    #[DataProvider('revisableMetricProvider')]
    public function revised_metric_value_updates_existing_event(
        string $jobClass,
        string $action,
        array $initialItem,
        array $revisedItem,
        int $expectedValue,
    ): void {
        $this->runJob($jobClass, [$initialItem]);
        $this->runJob($jobClass, [$revisedItem]);

        $events = Event::where('action', $action)->get();
        $this->assertCount(1, $events);
        $this->assertSame($expectedValue, (int) $events->first()->value);
    }

    #[Test]
    public function revised_sleep_time_recommendation_updates_existing_event(): void
    {
        $this->runJob(OuraSleepTimeData::class, [[
            'id' => 'sleep-time-1',
            'day' => '2026-09-26',
            'status' => 'not_enough_nights',
            'recommendation' => 'improve_efficiency',
        ]]);

        $this->runJob(OuraSleepTimeData::class, [[
            'id' => 'sleep-time-1',
            'day' => '2026-09-26',
            'status' => 'only_recommended_found',
            'recommendation' => 'earlier_bedtime',
        ]]);

        $events = Event::where('action', 'had_sleep_recommendation')->get();
        $this->assertCount(1, $events);
        $this->assertSame('only_recommended_found', $events->first()->event_metadata['status']);
    }

    #[Test]
    public function reprocessing_unchanged_sleep_data_does_not_duplicate_events_or_blocks(): void
    {
        $item = [
            'day' => '2026-09-23',
            'score' => 83,
            'contributors' => ['total_sleep' => 96, 'timing' => 88],
        ];

        $this->runJob(OuraSleepData::class, [$item]);
        $this->runJob(OuraSleepData::class, [$item]);

        $events = Event::where('action', 'had_sleep_score')->get();
        $this->assertCount(1, $events);
        $this->assertSame(83, (int) $events->first()->value);
        $this->assertSame(2, $events->first()->blocks()->where('block_type', 'contributor')->count());
    }

    #[Test]
    public function revision_of_soft_deleted_sleep_event_updates_it_without_restoring(): void
    {
        $this->runJob(OuraSleepData::class, [['day' => '2026-09-26', 'score' => 54]]);

        Event::where('action', 'had_sleep_score')->firstOrFail()->delete();

        $this->runJob(OuraSleepData::class, [['day' => '2026-09-26', 'score' => 89]]);

        $this->assertSame(0, Event::where('action', 'had_sleep_score')->count());

        $trashed = Event::onlyTrashed()->where('action', 'had_sleep_score')->get();
        $this->assertCount(1, $trashed);
        $this->assertSame(89, (int) $trashed->first()->value);
    }

    #[Test]
    public function revisions_are_scoped_to_their_own_day(): void
    {
        $this->runJob(OuraSleepData::class, [
            ['day' => '2026-09-25', 'score' => 34],
            ['day' => '2026-09-26', 'score' => 54],
        ]);

        $this->runJob(OuraSleepData::class, [
            ['day' => '2026-09-25', 'score' => 63],
            ['day' => '2026-09-26', 'score' => 89],
        ]);

        $scoresByDay = Event::where('action', 'had_sleep_score')
            ->get()
            ->mapWithKeys(fn (Event $event) => [$event->event_metadata['day'] => (int) $event->value]);

        $this->assertSame(['2026-09-25' => 63, '2026-09-26' => 89], $scoresByDay->sortKeys()->all());
    }

    #[Test]
    public function reprocessing_a_window_fetches_personal_info_once_per_job(): void
    {
        $items = collect(range(1, 5))
            ->map(fn (int $offset) => ['day' => "2026-09-0{$offset}", 'score' => 70 + $offset])
            ->all();

        $this->runJob(OuraSleepData::class, $items);

        $personalInfoRequests = Http::recorded(
            fn ($request) => str_contains($request->url(), '/usercollection/personal_info')
        );

        $this->assertCount(1, $personalInfoRequests);
        $this->assertSame(5, Event::where('action', 'had_sleep_score')->count());
    }

    /**
     * Run a processing job's process() directly, bypassing the idempotency cache so identical payloads are re-processed.
     *
     * @param  class-string<BaseProcessingJob>  $jobClass
     * @param  array<int, array<string, mixed>>  $items
     */
    private function runJob(string $jobClass, array $items): void
    {
        $job = new $jobClass($this->integration, $items);

        (new ReflectionMethod($job, 'process'))->invoke($job);
    }
}
