<?php

namespace App\Jobs\Data\Hevy;

use App\Jobs\Base\BaseProcessingJob;
use App\Models\Event;
use App\Models\EventObject;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class HevyWorkoutData extends BaseProcessingJob
{
    public function getIntegration()
    {
        return $this->integration;
    }

    public function getRawData()
    {
        return $this->rawData;
    }

    protected function getServiceName(): string
    {
        return 'hevy';
    }

    protected function getJobType(): string
    {
        return 'workout';
    }

    protected function process(): void
    {
        $workouts = $this->normalizeWorkouts($this->rawData);

        Log::info('Hevy: Processing workout data', [
            'integration_id' => $this->integration->id,
            'workout_count' => count($workouts),
        ]);

        foreach ($workouts as $workout) {
            if (! is_array($workout)) {
                Log::warning('Hevy: Skipping non-array workout item', [
                    'integration_id' => $this->integration->id,
                    'type' => gettype($workout),
                ]);

                continue;
            }

            $this->createWorkoutEvent($workout);
        }

        Log::info('Hevy: Completed processing workout data', [
            'integration_id' => $this->integration->id,
        ]);
    }

    private function normalizeWorkouts(array $rawData): array
    {
        $items = [];
        if (is_array($rawData)) {
            if (isset($rawData['data']) && is_array($rawData['data'])) {
                $items = $rawData['data'];
            } elseif (isset($rawData['workouts']) && is_array($rawData['workouts'])) {
                $items = $rawData['workouts'];
            } elseif (array_is_list($rawData)) {
                $items = $rawData;
            }
        }

        return $items;
    }

    private function createWorkoutEvent(array $workout): void
    {
        $workoutId = (string) (Arr::get($workout, 'id') ?? md5(json_encode([$this->integration->id, Arr::get($workout, 'start_time'), Arr::get($workout, 'title')])));
        $startIso = Arr::get($workout, 'start_time') ?? Arr::get($workout, 'date') ?? now()->toIso8601String();
        $endIso = Arr::get($workout, 'end_time');
        $title = (string) (Arr::get($workout, 'title') ?? 'Workout');
        $volume = (float) (Arr::get($workout, 'total_volume', 0.0));
        $durationSec = (int) (Arr::get($workout, 'duration_seconds', 0));

        $sourceId = "hevy_workout_{$this->integration->id}_{$workoutId}";

        // Check if this event already exists
        $exists = Event::where('source_id', $sourceId)->where('integration_id', $this->integration->id)->first();
        if ($exists) {
            Log::debug('Hevy: Workout event already exists, skipping', [
                'integration_id' => $this->integration->id,
                'source_id' => $sourceId,
            ]);

            return;
        }

        $actor = $this->ensureUserProfile();
        $target = EventObject::updateOrCreate([
            'user_id' => $this->integration->user_id,
            'concept' => 'workout',
            'type' => 'hevy_workout',
            'title' => $title,
            'time' => $startIso, // Add time as discriminator to prevent collapsing identical titles
        ], [
            'content' => Arr::get($workout, 'notes') ?? 'Hevy workout',
            'metadata' => $workout,
            'url' => Arr::get($workout, 'url'),
        ]);

        [$encVolume, $volMult] = $this->encodeNumericValue($volume);
        $event = Event::create([
            'source_id' => $sourceId,
            'time' => $startIso,
            'integration_id' => $this->integration->id,
            'actor_id' => $actor->id,
            'service' => 'hevy',
            'domain' => 'health',
            'action' => 'completed_workout',
            'value' => $encVolume,
            'value_multiplier' => $volMult,
            'value_unit' => $this->inferWeightUnit(Arr::get($workout, 'weight_unit')),
            'event_metadata' => [
                'end' => $endIso,
                'duration_seconds' => $durationSec,
            ],
            'target_id' => $target->id,
        ]);

        // Create blocks per exercise set
        $exercises = Arr::get($workout, 'exercises', []);
        $includeExerciseSummary = in_array('enabled', ($this->integration->configuration['include_exercise_summary_blocks'] ?? ['enabled']), true);

        foreach ($exercises as $exercise) {
            $exerciseName = (string) (Arr::get($exercise, 'name') ?? 'Exercise');
            $sets = Arr::get($exercise, 'sets', []);
            $exerciseVolume = 0.0;

            foreach ($sets as $index => $set) {
                $reps = (int) (Arr::get($set, 'reps', 0));
                $weight = (float) (Arr::get($set, 'weight', 0));
                $rest = Arr::get($set, 'rest_seconds');
                $rpe = Arr::get($set, 'rpe');
                $setNum = $index + 1;
                $unit = $this->inferWeightUnit(Arr::get($set, 'weight_unit', Arr::get($exercise, 'weight_unit')));

                $exerciseVolume += ($weight * max(0, $reps));

                [$encWeight, $weightMult] = $this->encodeNumericValue($weight);
                $content = "**Exercise:** {$exerciseName}\n**Set:** {$setNum}\n**Reps:** {$reps}\n**Weight:** {$weight} {$unit}";
                if ($rpe !== null && $rpe !== '') {
                    $content .= "\n**RPE:** {$rpe}";
                }
                if ($rest !== null && $rest !== '') {
                    $content .= "\n**Rest:** {$rest} s";
                }

                $event->blocks()->create([
                    'block_type' => 'exercise',
                    'time' => $startIso,
                    'title' => $exerciseName . ' - Set ' . $setNum,
                    'metadata' => ['text' => $content],
                    'url' => null,
                    'media_url' => null,
                    'value' => $encWeight,
                    'value_multiplier' => $weightMult,
                    'value_unit' => $unit,
                ]);
            }

            if ($includeExerciseSummary && $exerciseName !== '') {
                [$encExVol, $exVolMult] = $this->encodeNumericValue($exerciseVolume);
                $event->blocks()->create([
                    'block_type' => 'exercise',
                    'time' => $startIso,
                    'title' => $exerciseName . ' - Total Volume',
                    'metadata' => ['text' => 'Total volume (weight x reps) for this exercise'],
                    'value' => $encExVol,
                    'value_multiplier' => $exVolMult,
                    'value_unit' => $this->inferWeightUnit(Arr::get($exercise, 'weight_unit')),
                ]);
            }
        }

        Log::info('Hevy: Created workout event', [
            'integration_id' => $this->integration->id,
            'event_id' => $event->id,
            'workout_title' => $title,
        ]);
    }

    private function ensureUserProfile(): EventObject
    {
        // Attempt to fetch user profile to enrich metadata
        $profile = [];
        try {
            $profile = $this->getJson('/v1/me');
        } catch (Throwable $e) {
            // ignore, create minimal object
        }

        $title = $this->integration->name ?: 'Hevy Account';

        return EventObject::updateOrCreate([
            'user_id' => $this->integration->user_id,
            'concept' => 'user',
            'type' => 'hevy_user',
            'title' => $title,
        ], [
            'integration_id' => $this->integration->id,
            'time' => now(),
            'content' => 'Hevy user account',
            'metadata' => is_array($profile) ? $profile : [],
            'url' => null,
            'media_url' => null,
        ]);
    }

    /**
     * Simple HTTP helper using API key authentication.
     */
    private function getJson(string $endpoint): array
    {
        $apiKey = (string) ($this->integration->configuration['api_key'] ?? config('services.hevy.api_key') ?? '');
        $url = 'https://api.hevyapp.com' . $endpoint;

        $response = Http::withHeaders([
            'api-key' => $apiKey,
        ])->get($url);

        if (! $response->successful()) {
            throw new RuntimeException('Hevy API request failed with status ' . $response->status());
        }

        return $response->json() ?? [];
    }

    /**
     * Encode a numeric value into an integer with a multiplier to retain precision.
     * Mirrors Oura implementation for consistency across integrations.
     * Returns [encodedInt|null, multiplier|null].
     */
    private function encodeNumericValue(null|int|float|string $raw, int $defaultMultiplier = 1): array
    {
        if ($raw === null || $raw === '') {
            return [null, null];
        }
        $float = (float) $raw;
        if (! is_finite($float)) {
            return [null, null];
        }
        if (fmod($float, 1.0) !== 0.0) {
            $multiplier = 1000;
            $intValue = (int) round($float * $multiplier);

            return [$intValue, $multiplier];
        }

        return [(int) $float, $defaultMultiplier];
    }

    private function inferWeightUnit(?string $workoutUnit): string
    {
        if ($workoutUnit !== null && in_array($workoutUnit, ['kg', 'lb'], true)) {
            return $workoutUnit;
        }

        $preferredUnit = $this->integration->configuration['units'] ?? 'kg';

        return $preferredUnit;
    }
}
