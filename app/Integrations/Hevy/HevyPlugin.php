<?php

namespace App\Integrations\Hevy;

use App\Integrations\Contracts\IntegrationPlugin;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class HevyPlugin implements IntegrationPlugin
{
    protected string $baseUrl = 'https://api.hevyapp.com';

    protected ?string $apiKey;

    public function __construct()
    {
        // Optional project-wide API key fallback
        $this->apiKey = config('services.hevy.api_key');
        // Do not throw in non-testing; we allow per-instance API key configuration
    }

    public static function getIdentifier(): string
    {
        return 'hevy';
    }

    public static function getDisplayName(): string
    {
        return 'Hevy';
    }

    public static function getDescription(): string
    {
        return 'Connect your Hevy account to import workouts. Each exercise set is represented as a block for detailed analysis.';
    }

    public static function getConfigurationSchema(): array
    {
        return [
            'api_key' => [
                'type' => 'string',
                'label' => 'API Key',
                'description' => 'Hevy API key used for requests (stored encrypted). If empty, the global HEVY_API_KEY will be used if configured.',
                'required' => false,
            ],
            'update_frequency_minutes' => [
                'type' => 'integer',
                'label' => 'Update frequency (minutes)',
                'default' => 30,
                'min' => 5,
                'max' => 1440,
            ],
            'days_back' => [
                'type' => 'integer',
                'label' => 'Days back to fetch on each run',
                'default' => 14,
                'min' => 1,
                'max' => 90,
            ],
            'units' => [
                'type' => 'select',
                'label' => 'Preferred weight units',
                'options' => [
                    'kg' => 'Kilograms',
                    'lb' => 'Pounds',
                ],
                'default' => 'kg',
            ],
            'include_exercise_summary_blocks' => [
                'type' => 'array',
                'label' => 'Include per-exercise summary blocks',
                'options' => [
                    'enabled' => 'Enabled',
                ],
            ],
        ];
    }

    public static function getInstanceTypes(): array
    {
        return [
            'workouts' => [
                'label' => 'Workouts',
                'schema' => self::getConfigurationSchema(),
            ],
        ];
    }

    public static function getServiceType(): string
    {
        return 'apikey';
    }

    public function initializeGroup(\App\Models\User $user): IntegrationGroup
    {
        return IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => static::getIdentifier(),
            'account_id' => null,
            'access_token' => null,
            'refresh_token' => null,
            'expiry' => null,
            'refresh_expiry' => null,
        ]);
    }

    /**
     * Back-compat helper used by some UI/tests: create a group-first and return a placeholder Integration.
     */
    public function initialize(\App\Models\User $user): Integration
    {
        $group = $this->initializeGroup($user);

        return Integration::create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => static::getIdentifier(),
            'name' => static::getDisplayName(),
            'instance_type' => null,
            'configuration' => [],
        ]);
    }

    public function createInstance(IntegrationGroup $group, string $instanceType, array $initialConfig = []): Integration
    {
        return Integration::create([
            'user_id' => $group->user_id,
            'integration_group_id' => $group->id,
            'service' => static::getIdentifier(),
            'name' => static::getDisplayName(),
            'instance_type' => $instanceType,
            'configuration' => $initialConfig,
        ]);
    }

    public function handleOAuthCallback(\Illuminate\Http\Request $request, IntegrationGroup $group): void
    {
        throw new Exception('Hevy integration uses API key authentication and does not support OAuth');
    }

    public function fetchData(Integration $integration): void
    {
        $daysBack = (int) ($integration->configuration['days_back'] ?? 14);
        $startDate = now()->subDays($daysBack)->toDateString();
        $endDate = now()->toDateString();

        $query = http_build_query([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'limit' => 100,
        ]);

        $endpoint = '/v1/workouts?' . $query;
        $json = [];
        try {
            $json = $this->getJson($endpoint, $integration);
        } catch (Throwable $e) {
            Log::error('Hevy workouts fetch failed', [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        // Normalize items from possible shapes
        $items = [];
        if (is_array($json)) {
            if (isset($json['data']) && is_array($json['data'])) {
                $items = $json['data'];
            } elseif (isset($json['workouts']) && is_array($json['workouts'])) {
                $items = $json['workouts'];
            } elseif (array_is_list($json)) {
                $items = $json;
            }
        }

        foreach ($items as $idx => $workout) {
            if (! is_array($workout)) {
                Log::warning('Skipping non-array workout item from Hevy response', [
                    'integration_id' => $integration->id,
                    'index' => $idx,
                    'type' => gettype($workout),
                ]);

                continue;
            }
            $this->createWorkoutEvent($integration, $workout);
        }
    }

    public function convertData(array $externalData, Integration $integration): array
    {
        // Not used; data is fetched directly and persisted as events/blocks
        return [];
    }

    public function handleWebhook(\Illuminate\Http\Request $request, Integration $integration): void
    {
        // Hevy integration is pull-based via API key; no webhooks
        throw new Exception('Hevy integration does not support webhooks');
    }

    /**
     * Simple HTTP helper using API key authentication.
     */
    protected function getJson(string $endpoint, Integration $integration): array
    {
        $apiKey = (string) ($integration->configuration['api_key'] ?? $this->apiKey ?? '');
        // In tests we allow empty; in production recommend providing api key
        $url = Str::startsWith($endpoint, '/') ? $this->baseUrl . $endpoint : $this->baseUrl . '/' . ltrim($endpoint, '/');
        $response = Http::withHeaders([
            'api-key' => $apiKey,
        ])->get($url);
        if (! $response->successful()) {
            throw new RuntimeException('Hevy API request failed with status ' . $response->status());
        }

        return $response->json() ?? [];
    }

    private function createWorkoutEvent(Integration $integration, array $workout): void
    {
        $workoutId = (string) (Arr::get($workout, 'id') ?? md5(json_encode([$integration->id, Arr::get($workout, 'start_time'), Arr::get($workout, 'title')])));
        $startIso = Arr::get($workout, 'start_time') ?? Arr::get($workout, 'date') ?? now()->toIso8601String();
        $endIso = Arr::get($workout, 'end_time');
        $title = (string) (Arr::get($workout, 'title') ?? 'Workout');
        $volume = (float) (Arr::get($workout, 'total_volume', 0.0));
        $durationSec = (int) (Arr::get($workout, 'duration_seconds', 0));

        $sourceId = "hevy_workout_{$integration->id}_{$workoutId}";
        $exists = Event::where('source_id', $sourceId)->where('integration_id', $integration->id)->first();
        if ($exists) {
            return;
        }

        $actor = $this->ensureUserProfile($integration);
        $target = EventObject::updateOrCreate([
            'user_id' => $integration->user_id,
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
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'service' => 'hevy',
            'domain' => 'fitness',
            'action' => 'completed_workout',
            'value' => $encVolume,
            'value_multiplier' => $volMult,
            'value_unit' => $this->inferWeightUnit($integration, Arr::get($workout, 'weight_unit')),
            'event_metadata' => [
                'end' => $endIso,
                'duration_seconds' => $durationSec,
            ],
            'target_id' => $target->id,
        ]);

        // Create blocks per exercise set
        $exercises = Arr::get($workout, 'exercises', []);
        $includeExerciseSummary = in_array('enabled', ($integration->configuration['include_exercise_summary_blocks'] ?? ['enabled']), true);

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
                $unit = $this->inferWeightUnit($integration, Arr::get($set, 'weight_unit', Arr::get($exercise, 'weight_unit')));

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
                    'time' => $startIso,
                    'title' => $exerciseName . ' - Set ' . $setNum,
                    'content' => $content,
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
                    'time' => $startIso,
                    'title' => $exerciseName . ' - Total Volume',
                    'content' => 'Total volume (weight x reps) for this exercise',
                    'value' => $encExVol,
                    'value_multiplier' => $exVolMult,
                    'value_unit' => $this->inferWeightUnit($integration, Arr::get($exercise, 'weight_unit')),
                ]);
            }
        }
    }

    private function ensureUserProfile(Integration $integration): EventObject
    {
        // Attempt to fetch user profile to enrich metadata
        $profile = [];
        try {
            $profile = $this->getJson('/v1/me', $integration);
        } catch (Throwable $e) {
            // ignore, create minimal object
        }

        $title = $integration->name ?: 'Hevy Account';

        return EventObject::updateOrCreate([
            'user_id' => $integration->user_id,
            'concept' => 'user',
            'type' => 'hevy_user',
            'title' => $title,
        ], [
            'integration_id' => $integration->id,
            'time' => now(),
            'content' => 'Hevy user account',
            'metadata' => is_array($profile) ? $profile : [],
            'url' => null,
            'media_url' => null,
        ]);
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

    private function inferWeightUnit(Integration $integration, ?string $candidate): ?string
    {
        $unit = $candidate ?: ($integration->configuration['units'] ?? 'kg');
        $unit = strtolower((string) $unit);

        return in_array($unit, ['kg', 'lb'], true) ? $unit : 'kg';
    }
}
