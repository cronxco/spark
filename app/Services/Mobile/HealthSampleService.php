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
 * already know how to process. Dedupe is passive — the jobs `updateOrCreate`
 * events keyed on `(integration_id, source_id)` so replays are safe; this
 * service also reports back which samples are already on record.
 */
class HealthSampleService
{
    public function ingest(User $user, array $samples): array
    {
        $metricsIntegration = $this->resolveIntegration($user, 'metrics');
        $workoutsIntegration = $this->resolveIntegration($user, 'workouts');

        $results = [];
        $metricBuckets = [];
        $workoutBatch = [];

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

            $sourceId = 'apple_metric_' . $metricName . '_' . $this->normalizeDate($sample['start'] ?? null);
            if ($this->eventExists($metricsIntegration, $sourceId)) {
                $results[] = ['external_id' => $externalId, 'status' => 'duplicate'];

                continue;
            }

            $unit = (string) ($sample['unit'] ?? '');
            $metricBuckets[$metricName] ??= ['name' => $metricName, 'units' => $unit, 'data' => []];
            $metricBuckets[$metricName]['data'][] = [
                'date' => $this->normalizeDate($sample['start'] ?? null),
                'qty' => $sample['value'] ?? null,
                'source' => $sample['source'] ?? null,
            ];
            $results[] = ['external_id' => $externalId, 'status' => 'accepted'];
        }

        foreach ($metricBuckets as $bucket) {
            AppleHealthMetricData::dispatch($metricsIntegration, $bucket);
        }

        foreach ($workoutBatch as $workout) {
            AppleHealthWorkoutData::dispatch($workoutsIntegration, $workout);
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
