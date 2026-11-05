<?php

namespace App\Services\Mobile;

use App\Jobs\Data\AppleHealth\AppleHealthMetricData;
use App\Jobs\Data\AppleHealth\AppleHealthWorkoutData;
use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Ingests HealthKit samples POSTed from the iOS client and turns them into the
 * `$rawData` shape that `AppleHealthMetricData` and `AppleHealthWorkoutData`
 * already know how to process. The jobs `updateOrCreate` events keyed on
 * `(integration_id, source_id)`, so replays are safe.
 *
 * A metric sample is the day's reading for that metric: one event per metric
 * per day. The phone re-sends a day's running total as it grows, so a reading
 * taken later (`metadata.as_of`, else `end`) replaces the stored one rather
 * than being turned away as a duplicate. Only a reading no newer than the one
 * on record is a duplicate.
 */
class HealthSampleService
{
    public function ingest(User $user, array $samples): array
    {
        $metricsIntegration = $this->resolveIntegration($user, 'metrics');
        $workoutsIntegration = $this->resolveIntegration($user, 'workouts');

        $results = [];
        // The newest reading in this batch for each metric-day, keyed
        // "{metric}|{date}".
        $metricDays = [];
        $workoutBatch = [];
        // Which instances this batch actually synced — accepted or already on
        // record. A batch of rejected samples says nothing about either.
        $metricsSynced = false;
        $workoutsSynced = false;

        foreach ($samples as $sample) {
            $externalId = (string) ($sample['external_id'] ?? '');
            $type = (string) ($sample['type'] ?? '');

            if ($externalId === '' || $type === '') {
                $results[] = [
                    'external_id' => $externalId,
                    'status' => 'rejected',
                    'reason' => 'missing external_id or type',
                ];

                continue;
            }

            if ($this->isWorkout($type)) {
                $sourceId = $externalId;
                $workoutsSynced = true;
                if ($this->eventExists($workoutsIntegration, $sourceId)) {
                    $results[] = ['external_id' => $externalId, 'status' => 'duplicate'];

                    continue;
                }

                $workoutBatch[] = $this->toWorkoutPayload($externalId, $sample);
                $results[] = ['external_id' => $externalId, 'status' => 'accepted'];

                continue;
            }

            $metricName = $this->metricNameFromType($type);
            if ($metricName === null) {
                $results[] = [
                    'external_id' => $externalId,
                    'status' => 'rejected',
                    'reason' => 'unknown sample type',
                ];

                continue;
            }

            $metricsSynced = true;
            $day = $this->sampleDay($sample);
            $asOf = $this->sampleAsOf($sample);
            $key = $metricName . '|' . $day;

            if (isset($metricDays[$key])) {
                if ($metricDays[$key]['as_of']->gte($asOf)) {
                    $results[] = ['external_id' => $externalId, 'status' => 'duplicate'];

                    continue;
                }

                // A newer reading later in the batch supersedes this one.
                $results[$metricDays[$key]['result']]['status'] = 'duplicate';
                $onRecord = $metricDays[$key]['on_record'];
            } else {
                $onRecord = $this->storedReading($metricsIntegration, 'apple_metric_' . $metricName . '_' . $day);
                if ($onRecord['as_of'] !== null && $onRecord['as_of']->gte($asOf)) {
                    $results[] = ['external_id' => $externalId, 'status' => 'duplicate'];

                    continue;
                }
            }

            $results[] = ['external_id' => $externalId, 'status' => $onRecord['exists'] ? 'updated' : 'accepted'];
            $metricDays[$key] = [
                'name' => $metricName,
                'unit' => (string) ($sample['unit'] ?? ''),
                'as_of' => $asOf,
                'on_record' => $onRecord,
                'result' => array_key_last($results),
                'point' => [
                    'date' => $day,
                    'qty' => $sample['value'] ?? null,
                    'source' => $sample['source'] ?? null,
                    'as_of' => $asOf->toIso8601String(),
                ],
            ];
        }

        $metricBuckets = [];
        foreach ($metricDays as $reading) {
            $metricBuckets[$reading['name']] ??= ['name' => $reading['name'], 'units' => $reading['unit'], 'data' => []];
            $metricBuckets[$reading['name']]['data'][] = $reading['point'];
        }

        foreach ($metricBuckets as $bucket) {
            AppleHealthMetricData::dispatch($metricsIntegration, $bucket);
        }

        foreach ($workoutBatch as $workout) {
            AppleHealthWorkoutData::dispatch($workoutsIntegration, $workout);
        }

        // A batch is a sync from the phone for each instance it carried,
        // duplicates included — the day summary's freshness reads it.
        if ($metricsSynced) {
            $metricsIntegration->markAsSuccessfullyUpdated();
        }
        if ($workoutsSynced) {
            $workoutsIntegration->markAsSuccessfullyUpdated();
        }

        return $results;
    }

    protected function resolveIntegration(User $user, string $instanceType): Integration
    {
        return DB::transaction(function () use ($user, $instanceType) {
            $group = IntegrationGroup::firstOrCreate(
                ['user_id' => $user->id, 'service' => 'apple_health'],
                [
                    'account_id' => Str::uuid()->toString(),
                    'access_token' => 'mobile',
                ],
            );

            return Integration::firstOrCreate(
                [
                    'user_id' => $user->id,
                    'service' => 'apple_health',
                    'instance_type' => $instanceType,
                ],
                [
                    'integration_group_id' => $group->id,
                    'name' => 'Apple Health',
                    'account_id' => $group->account_id,
                    'configuration' => ['update_frequency_minutes' => 0],
                ],
            );
        });
    }

    protected function eventExists(Integration $integration, string $sourceId): bool
    {
        return Event::where('integration_id', $integration->id)
            ->where('source_id', $sourceId)
            ->exists();
    }

    /**
     * Whether a metric-day is on record, and when its reading was taken. Events
     * written before readings carried `as_of` have none, so any reading
     * replaces them.
     *
     * @return array{exists: bool, as_of: ?Carbon}
     */
    protected function storedReading(Integration $integration, string $sourceId): array
    {
        $event = Event::where('integration_id', $integration->id)
            ->where('source_id', $sourceId)
            ->first(['id', 'event_metadata']);

        if ($event === null) {
            return ['exists' => false, 'as_of' => null];
        }

        $asOf = data_get($event->event_metadata, 'raw.as_of');

        return ['exists' => true, 'as_of' => is_string($asOf) ? $this->parseTime($asOf) : null];
    }

    /**
     * The local day a metric reading belongs to. The client sends it as
     * `metadata.date`, because `start` arrives in UTC and the local midnight
     * that begins a day falls on the previous UTC date for anyone east of UTC.
     */
    protected function sampleDay(array $sample): string
    {
        $date = $sample['metadata']['date'] ?? null;
        if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            return $date;
        }

        return $this->normalizeDate($sample['start'] ?? null);
    }

    /** When a reading was taken: `metadata.as_of`, else the sample's `end`, else `start`. */
    protected function sampleAsOf(array $sample): Carbon
    {
        foreach ([$sample['metadata']['as_of'] ?? null, $sample['end'] ?? null, $sample['start'] ?? null] as $candidate) {
            if (is_string($candidate) && $candidate !== '' && ($time = $this->parseTime($candidate)) !== null) {
                return $time;
            }
        }

        return now();
    }

    protected function parseTime(string $input): ?Carbon
    {
        try {
            return Carbon::parse($input);
        } catch (Throwable) {
            return null;
        }
    }

    protected function isWorkout(string $type): bool
    {
        return $type === 'HKWorkoutType' || str_starts_with($type, 'HKWorkoutActivityType');
    }

    protected function metricNameFromType(string $type): ?string
    {
        $prefix = 'HKQuantityTypeIdentifier';
        if (! str_starts_with($type, $prefix)) {
            return null;
        }

        $tail = substr($type, strlen($prefix));
        if ($tail === '') {
            return null;
        }

        return Str::snake($tail);
    }

    protected function normalizeDate(?string $input): string
    {
        if ($input === null || $input === '') {
            return now()->toDateString();
        }

        try {
            return Carbon::parse($input)->toDateString();
        } catch (Throwable) {
            return now()->toDateString();
        }
    }

    protected function toWorkoutPayload(string $externalId, array $sample): array
    {
        return [
            'id' => $externalId,
            'name' => $sample['metadata']['name'] ?? ($sample['type'] ?? 'Workout'),
            'start' => $sample['start'] ?? now()->toIso8601String(),
            'end' => $sample['end'] ?? ($sample['start'] ?? now()->toIso8601String()),
            'duration' => $sample['metadata']['duration'] ?? null,
            'distance' => isset($sample['value'], $sample['unit']) ? [
                'qty' => $sample['value'],
                'units' => $sample['unit'],
            ] : null,
        ];
    }
}
