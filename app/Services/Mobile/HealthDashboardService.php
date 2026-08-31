<?php

namespace App\Services\Mobile;

use App\Models\Block;
use App\Models\Event;
use App\Models\MetricStatistic;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class HealthDashboardService
{
    private const RANGE_DAYS = [
        '7d' => 7,
        '30d' => 30,
        '90d' => 90,
    ];

    /**
     * @var array<string, array{label: string, service: string, action: string, unit: string, lower_better?: bool}>
     */
    private const FITNESS_METRICS = [
        'steps' => ['label' => 'Steps', 'service' => 'apple_health', 'action' => 'had_step_count', 'unit' => 'steps'],
        'distance' => ['label' => 'Distance', 'service' => 'apple_health', 'action' => 'had_walking_running_distance', 'unit' => 'km'],
        'active_energy' => ['label' => 'Active Energy', 'service' => 'apple_health', 'action' => 'had_active_energy', 'unit' => 'kcal'],
        'exercise' => ['label' => 'Exercise', 'service' => 'apple_health', 'action' => 'had_apple_exercise_time', 'unit' => 'min'],
        'stand' => ['label' => 'Stand', 'service' => 'apple_health', 'action' => 'had_apple_stand_hour', 'unit' => 'hours'],
    ];

    /**
     * @var array<string, array{label: string, service: string, action: string, unit: string, lower_better?: bool}>
     */
    private const BODY_METRICS = [
        'readiness' => ['label' => 'Readiness', 'service' => 'oura', 'action' => 'had_readiness_score', 'unit' => 'percent'],
        'sleep_score' => ['label' => 'Sleep Score', 'service' => 'oura', 'action' => 'had_sleep_score', 'unit' => 'percent'],
        'hrv' => ['label' => 'HRV', 'service' => 'apple_health', 'action' => 'had_heart_rate_variability', 'unit' => 'ms'],
        'resting_heart_rate' => ['label' => 'Resting Heart Rate', 'service' => 'apple_health', 'action' => 'had_resting_heart_rate', 'unit' => 'bpm', 'lower_better' => true],
        'respiratory_rate' => ['label' => 'Respiratory Rate', 'service' => 'apple_health', 'action' => 'had_respiratory_rate', 'unit' => 'breaths/min'],
        'spo2' => ['label' => 'SpO2', 'service' => 'oura', 'action' => 'had_spo2', 'unit' => 'percent'],
        'blood_oxygen' => ['label' => 'SpO2', 'service' => 'apple_health', 'action' => 'had_blood_oxygen_saturation', 'unit' => 'percent'],
        'wrist_temperature' => ['label' => 'Wrist Temperature', 'service' => 'apple_health', 'action' => 'had_apple_sleeping_wrist_temperature', 'unit' => '°C', 'lower_better' => true],
        'temperature_deviation' => ['label' => 'Wrist Temperature', 'service' => 'oura', 'action' => 'had_temperature_deviation', 'unit' => 'celsius', 'lower_better' => true],
        'stress' => ['label' => 'Stress', 'service' => 'oura', 'action' => 'had_stress_score', 'unit' => 'stress_level', 'lower_better' => true],
        'resilience' => ['label' => 'Resilience', 'service' => 'oura', 'action' => 'had_resilience_score', 'unit' => 'resilience_level'],
        'cardiovascular_age' => ['label' => 'Cardiovascular Age', 'service' => 'oura', 'action' => 'had_cardiovascular_age', 'unit' => 'years', 'lower_better' => true],
        'vo2_max' => ['label' => 'VO2 Max', 'service' => 'apple_health', 'action' => 'had_vo2_max', 'unit' => 'mL/kg/min'],
        'oura_vo2_max' => ['label' => 'VO2 Max', 'service' => 'oura', 'action' => 'had_vo2_max', 'unit' => 'mL/kg/min'],
    ];

    /**
     * @var array<string, array{label: string, service: string, action: string, unit: string}>
     */
    private const TREND_METRICS = [
        'readiness' => ['label' => 'Readiness', 'service' => 'oura', 'action' => 'had_readiness_score', 'unit' => 'percent'],
        'sleep_score' => ['label' => 'Sleep Score', 'service' => 'oura', 'action' => 'had_sleep_score', 'unit' => 'percent'],
        'active_energy' => ['label' => 'Active Energy', 'service' => 'apple_health', 'action' => 'had_active_energy', 'unit' => 'kcal'],
        'exercise' => ['label' => 'Exercise Minutes', 'service' => 'apple_health', 'action' => 'had_apple_exercise_time', 'unit' => 'min'],
        'steps' => ['label' => 'Steps', 'service' => 'apple_health', 'action' => 'had_step_count', 'unit' => 'steps'],
        'distance' => ['label' => 'Distance', 'service' => 'apple_health', 'action' => 'had_walking_running_distance', 'unit' => 'km'],
        'workout_energy' => ['label' => 'Workout Energy', 'service' => 'apple_health', 'action' => 'did_workout', 'unit' => 'kcal'],
        'hevy_volume' => ['label' => 'Strength Volume', 'service' => 'hevy', 'action' => 'completed_workout', 'unit' => 'kg'],
    ];

    public function __construct(private MetricTrendService $trendService) {}

    /**
     * @return array<string, mixed>
     */
    public function dashboard(User $user, Carbon $date, string $range): array
    {
        $events = $this->eventsForDate($user, $date);
        $statistics = $this->statisticsForEvents($user, $events);
        $workouts = $this->buildWorkouts($events);
        $bodyMetrics = $this->buildBodyMetrics($events, $statistics);
        $hero = $this->buildHero($bodyMetrics, $events);

        return [
            'date' => $date->toDateString(),
            'timezone' => $user->timezone ?? config('app.timezone', 'UTC'),
            'range' => $range,
            'generated_at' => now()->toIso8601String(),
            'sync_status' => $this->buildSyncStatus($events),
            'hero' => $hero,
            'fitness' => [
                'today' => $this->buildFitnessToday($events, $statistics, $workouts),
                'workouts' => $workouts,
            ],
            'body_metrics' => $bodyMetrics,
            'trends' => $this->buildTrends($user, $date, $range),
            'insights' => $this->buildInsights($events),
        ];
    }

    private function eventsForDate(User $user, Carbon $date): Collection
    {
        return Event::query()
            ->withoutInternal()
            ->whereHas('integration', fn ($q) => $q->where('user_id', $user->id))
            ->whereBetween('time', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->with(['integration', 'actor', 'target', 'blocks', 'tags'])
            ->orderBy('time')
            ->limit(1000)
            ->get();
    }

    /**
     * @return array<string, MetricStatistic>
     */
    private function statisticsForEvents(User $user, Collection $events): array
    {
        $keys = $events
            ->filter(fn (Event $event) => $event->value !== null && $event->value_unit !== null)
            ->map(fn (Event $event) => "{$event->service}.{$event->action}.{$event->value_unit}")
            ->unique()
            ->values();

        if ($keys->isEmpty()) {
            return [];
        }

        return MetricStatistic::query()
            ->where('user_id', $user->id)
            ->where(function ($query) use ($keys) {
                foreach ($keys as $key) {
                    [$service, $action, $unit] = explode('.', $key, 3);
                    $query->orWhere(fn ($q) => $q
                        ->where('service', $service)
                        ->where('action', $action)
                        ->where('value_unit', $unit));
                }
            })
            ->get()
            ->keyBy(fn (MetricStatistic $statistic) => $statistic->getIdentifier())
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFitnessToday(Collection $events, array $statistics, array $workouts): array
    {
        $today = [];

        foreach (self::FITNESS_METRICS as $key => $config) {
            $event = $this->firstMetricEvent($events, $config);
            if (! $event) {
                continue;
            }

            $entry = [
                'value' => $this->rounded($event->formatted_value),
                'unit' => $event->value_unit ?? $config['unit'],
            ];

            if (($baseline = $this->baselineComparison($event, $statistics)) !== null) {
                $entry['vs_baseline_pct'] = $baseline['vs_baseline_pct'];
            }

            $today[$key] = $entry;
        }

        $today['workout_count'] = count($workouts);
        $today['workout_duration_seconds'] = $this->rounded(collect($workouts)->sum(fn ($workout) => $workout['duration_seconds'] ?? 0));
        $today['workout_energy_kcal'] = $this->rounded(collect($workouts)->sum(fn ($workout) => $workout['energy_kcal'] ?? 0));

        $strengthVolume = collect($workouts)
            ->where('source', 'hevy')
            ->sum(fn ($workout) => Arr::get($workout, 'volume.value', 0));

        if ($strengthVolume > 0) {
            $today['strength_volume'] = [
                'value' => $this->rounded($strengthVolume),
                'unit' => collect($workouts)->firstWhere('source', 'hevy')['volume']['unit'] ?? 'kg',
            ];
        }

        return $today;
    }

    private function buildWorkouts(Collection $events): array
    {
        $cardio = collect($events
            ->filter(fn (Event $event) => $event->action === 'did_workout' && in_array($event->service, ['apple_health', 'oura'], true))
            ->map(fn (Event $event) => $this->normaliseCardioWorkout($event))
            ->filter()
            ->values()
            ->all());

        $deduped = $this->dedupeCardioWorkouts($cardio);

        $strength = collect($events
            ->filter(fn (Event $event) => $event->service === 'hevy' && $event->action === 'completed_workout')
            ->map(fn (Event $event) => $this->normaliseHevyWorkout($event))
            ->values()
            ->all());

        return $deduped
            ->merge($strength)
            ->sortBy('start')
            ->values()
            ->all();
    }

    private function normaliseCardioWorkout(Event $event): ?array
    {
        $metadata = $event->event_metadata ?? [];
        $distance = $this->metricBlock($event, 'distance');
        $energy = $this->metricBlock($event, 'energy');
        $intensity = $this->metricBlock($event, 'intensity');
        $duration = $this->metricBlock($event, 'duration');
        $end = Arr::get($metadata, 'end') ?? Arr::get($metadata, 'end_datetime');
        $durationSeconds = Arr::get($metadata, 'duration_seconds') ?? $duration?->formatted_value ?? 0;

        return [
            'event_id' => $event->id,
            'source' => $event->service,
            'kind' => 'cardio',
            'type' => $this->cleanWorkoutType($event->target?->title ?? Arr::get($metadata, 'activity_type') ?? 'Workout'),
            'title' => $this->cleanWorkoutType($event->target?->title ?? Arr::get($metadata, 'activity_type') ?? 'Workout'),
            'start' => $event->time->toIso8601String(),
            'end' => $end ? Carbon::parse($end)->toIso8601String() : $event->time->copy()->addSeconds((float) $durationSeconds)->toIso8601String(),
            'duration_seconds' => $this->rounded($durationSeconds),
            'energy_kcal' => $this->rounded($energy?->formatted_value ?? $event->formatted_value ?? 0),
            'distance' => $this->valueUnit($distance?->formatted_value ?? Arr::get($metadata, 'distance'), $distance?->value_unit ?? Arr::get($metadata, 'distance_unit')),
            'intensity' => $this->valueUnit($intensity?->formatted_value ?? Arr::get($metadata, 'intensity'), $intensity?->value_unit ?? Arr::get($metadata, 'intensity_unit')),
            'route_available' => (int) Arr::get($metadata, 'route_summary.total_points', 0) > 0
                || ! empty(Arr::get($metadata, 'route_points', [])),
        ];
    }

    private function normaliseHevyWorkout(Event $event): array
    {
        $summaries = $event->blocks->where('block_type', 'exercise_summary');
        $exercises = $summaries->isNotEmpty()
            ? $summaries->map(fn (Block $block) => [
                'name' => Arr::get($block->metadata, 'exercise_name') ?? Str::before($block->title, ' - Total Volume'),
                'sets' => (int) Arr::get($block->metadata, 'sets_count', 0),
                'volume' => $this->valueUnit($block->formatted_value, $block->value_unit ?? Arr::get($block->metadata, 'unit', 'kg')),
            ])->values()->all()
            : $event->blocks
                ->where('block_type', 'exercise')
                ->groupBy(fn (Block $block) => Arr::get($block->metadata, 'exercise_name') ?? Str::before($block->title, ' - Set '))
                ->map(fn (Collection $blocks, string $name) => [
                    'name' => $name,
                    'sets' => $blocks->count(),
                    'volume' => $this->valueUnit(
                        $blocks->sum(fn (Block $block) => ((float) Arr::get($block->metadata, 'weight', 0)) * ((int) Arr::get($block->metadata, 'reps', 0))),
                        $blocks->first()?->value_unit ?? 'kg',
                    ),
                ])->values()->all();

        return [
            'event_id' => $event->id,
            'source' => 'hevy',
            'kind' => 'strength',
            'title' => $this->cleanWorkoutType($event->target?->title ?? 'Workout'),
            'start' => $event->time->toIso8601String(),
            'duration_seconds' => $this->rounded(Arr::get($event->event_metadata ?? [], 'duration_seconds', 0)),
            'volume' => $this->valueUnit($event->formatted_value, $event->value_unit ?? 'kg'),
            'exercises' => $exercises,
        ];
    }

    private function dedupeCardioWorkouts(Collection $workouts): Collection
    {
        $apple = $workouts->where('source', 'apple_health')->values();
        $oura = $workouts->where('source', 'oura')->reject(function (array $ouraWorkout) use ($apple) {
            return $apple->contains(function (array $appleWorkout) use ($ouraWorkout) {
                $minutes = abs(Carbon::parse($appleWorkout['start'])->diffInMinutes(Carbon::parse($ouraWorkout['start']), false));
                $appleEnergy = (float) ($appleWorkout['energy_kcal'] ?? 0);
                $ouraEnergy = (float) ($ouraWorkout['energy_kcal'] ?? 0);
                $energyDeltaPct = $appleEnergy > 0
                    ? abs($appleEnergy - $ouraEnergy) / $appleEnergy * 100
                    : ($ouraEnergy === 0.0 ? 0 : 100);

                return $minutes <= 10 && $energyDeltaPct < 15;
            });
        })->values();

        return $apple->merge($oura);
    }

    private function buildBodyMetrics(Collection $events, array $statistics): array
    {
        $items = [];
        $seenLabels = [];

        foreach (self::BODY_METRICS as $key => $config) {
            $event = $this->firstMetricEvent($events, $config);
            if (! $event || isset($seenLabels[$config['label']])) {
                continue;
            }

            $baseline = $this->baselineComparison($event, $statistics);
            $items[] = [
                'id' => $event->service . '.' . $event->action . '.' . $event->value_unit,
                'event_id' => $event->id,
                'label' => $config['label'],
                'value' => $this->rounded($event->formatted_value),
                'unit' => $event->value_unit ?? $config['unit'],
                'vs_baseline_pct' => $baseline['vs_baseline_pct'] ?? null,
                'is_anomaly' => $baseline['is_anomaly'] ?? false,
                'status' => $this->status($event->formatted_value, $baseline, (bool) ($config['lower_better'] ?? false)),
            ];
            $seenLabels[$config['label']] = true;
        }

        return $items;
    }

    private function buildHero(array $bodyMetrics, Collection $events): ?array
    {
        $readiness = collect($bodyMetrics)->firstWhere('label', 'Readiness');
        $primary = $readiness ?? collect($bodyMetrics)->first(fn ($metric) => in_array($metric['status'], ['critical', 'low'], true));

        if (! $primary) {
            return null;
        }

        $score = $primary['label'] === 'Readiness'
            ? (int) round((float) $primary['value'])
            : null;

        $status = $primary['status'];
        $baseline = $primary['vs_baseline_pct'];
        $title = match ($status) {
            'critical', 'low' => 'Take a lighter day',
            'high' => 'Ready to push',
            default => 'Health looks steady',
        };

        return [
            'score' => $score,
            'kind' => Str::snake($primary['label']),
            'status' => $status,
            'title' => $title,
            'subtitle' => $baseline === null
                ? $primary['label'] . ' is available for today.'
                : $primary['label'] . ' is ' . abs($baseline) . '% ' . ($baseline < 0 ? 'below' : 'above') . ' baseline.',
            'primary_event_id' => $primary['event_id'],
            'factors' => $this->heroFactors($events, $primary['event_id']),
        ];
    }

    private function heroFactors(Collection $events, string $eventId): array
    {
        $event = $events->firstWhere('id', $eventId);
        if (! $event) {
            return [];
        }

        return $event->blocks
            ->where('block_type', 'contributor')
            ->take(4)
            ->map(fn (Block $block) => [
                'label' => $block->title,
                'value' => $this->rounded($block->formatted_value),
                'unit' => $block->value_unit,
                'status' => ((float) $block->formatted_value) < 0 ? 'low' : 'normal',
            ])
            ->values()
            ->all();
    }

    private function buildTrends(User $user, Carbon $date, string $range): array
    {
        $days = self::RANGE_DAYS[$range] ?? 7;
        $from = $date->copy()->subDays($days - 1)->toDateString();
        $to = $date->toDateString();

        return collect(self::TREND_METRICS)
            ->map(function (array $config) use ($user, $from, $to) {
                $identifier = "{$config['service']}.{$config['action']}.{$config['unit']}";
                $trend = $this->trendService->trend($user, $identifier, $from, $to);
                if ($trend === null) {
                    return null;
                }

                $trend['label'] = $config['label'];

                return $trend;
            })
            ->filter()
            ->values()
            ->all();
    }

    private function buildInsights(Collection $events): array
    {
        return $events
            ->flatMap(fn (Event $event) => $event->blocks->map(fn (Block $block) => [$event, $block]))
            ->filter(function (array $pair) {
                /** @var Block $block */
                $block = $pair[1];
                if ($block->block_type === 'flint_health_insight') {
                    return true;
                }

                if ($block->block_type !== 'flint_insight') {
                    return false;
                }

                $content = strtolower($block->title . ' ' . ($block->getContent() ?? ''));

                return Str::contains($content, ['health', 'readiness', 'workout', 'sleep', 'recovery']);
            })
            // The blocks relation is unordered, so without this the three
            // insights surfaced are whichever three the database happened to
            // return. Read them chronologically, with the id breaking ties.
            ->sortBy(fn (array $pair) => [
                ($pair[1]->time ?? $pair[0]->time)?->getTimestamp() ?? 0,
                (string) $pair[1]->id,
            ])
            ->take(3)
            ->map(function (array $pair) {
                /** @var Event $event */
                /** @var Block $block */
                [$event, $block] = $pair;

                return [
                    'block_id' => $block->id,
                    'event_id' => $event->id,
                    'title' => $block->title,
                    'content' => $block->getContent(),
                    'time' => ($block->time ?? $event->time)->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    private function buildSyncStatus(Collection $events): array
    {
        return $events
            ->groupBy('service')
            ->map(function (Collection $serviceEvents, string $service) {
                $last = $serviceEvents->sortByDesc('time')->first();
                $status = [
                    'event_count' => $serviceEvents->count(),
                    'last_event_time' => $last?->time?->toIso8601String(),
                ];

                if ($service === 'apple_health') {
                    $status['coverage'] = $last && $last->time->diffInHours(now()) <= 2 ? 'complete' : 'partial';
                }

                return $status;
            })
            ->all();
    }

    private function firstMetricEvent(Collection $events, array $config): ?Event
    {
        return $events
            ->where('service', $config['service'])
            ->where('action', $config['action'])
            ->sortByDesc('time')
            ->first();
    }

    private function baselineComparison(Event $event, array $statistics): ?array
    {
        $key = "{$event->service}.{$event->action}.{$event->value_unit}";
        $statistic = $statistics[$key] ?? null;

        if (! $statistic instanceof MetricStatistic || ! $statistic->hasValidStatistics()) {
            return null;
        }

        $value = (float) $event->formatted_value;
        $mean = (float) $statistic->mean_value;

        return [
            'mean' => $mean,
            'normal_lower' => (float) $statistic->normal_lower_bound,
            'normal_upper' => (float) $statistic->normal_upper_bound,
            'sample_days' => $statistic->event_count,
            'vs_baseline_pct' => $mean !== 0.0 ? round((($value - $mean) / abs($mean)) * 100, 1) : 0.0,
            'is_anomaly' => $value < (float) $statistic->normal_lower_bound || $value > (float) $statistic->normal_upper_bound,
        ];
    }

    private function status(float|int|string|null $value, ?array $baseline, bool $lowerBetter): string
    {
        if ($baseline === null) {
            return 'normal';
        }

        $value = (float) $value;
        $vs = (float) $baseline['vs_baseline_pct'];
        $worse = $lowerBetter ? $vs : -$vs;
        $better = $lowerBetter ? -$vs : $vs;

        if (($baseline['is_anomaly'] && $worse >= 20) || $value < (float) $baseline['normal_lower']) {
            return 'critical';
        }

        if ($worse >= 10) {
            return 'low';
        }

        if ($better >= 10) {
            return 'high';
        }

        return 'normal';
    }

    private function metricBlock(Event $event, string $type): ?Block
    {
        return $event->blocks->first(fn (Block $block) => $block->block_type === $type
            || Arr::get($block->metadata ?? [], 'field') === $type
            || Arr::get($block->metadata ?? [], 'type') === $type);
    }

    private function valueUnit(mixed $value, ?string $unit): ?array
    {
        if ($value === null || $unit === null) {
            return null;
        }

        return [
            'value' => $this->rounded($value),
            'unit' => $unit,
        ];
    }

    private function cleanWorkoutType(string $title): string
    {
        $title = preg_replace('/\s+\([^)]+\)$/', '', $title) ?: $title;

        return trim(Str::of($title)->replace('_', ' ')->title()->toString());
    }

    private function rounded(mixed $value): int|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        $rounded = round((float) $value, 3);

        return fmod($rounded, 1.0) === 0.0 ? (int) $rounded : $rounded;
    }
}
