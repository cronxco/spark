<?php

namespace App\Integrations\Hevy;

use App\Integrations\Contracts\IntegrationPlugin;
use App\Integrations\Contracts\SupportsEffects;
use App\Integrations\Contracts\SupportsTaskPipeline;
use App\Jobs\Effects\Hevy\HevyAnalyzeProgressionEffect;
use App\Jobs\Effects\Hevy\HevyAutoCoachEffect;
use App\Jobs\Effects\Hevy\HevyUpdateRoutineEffect;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Services\TaskPipeline\TaskDefinition;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class HevyPlugin implements IntegrationPlugin, SupportsEffects, SupportsTaskPipeline
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
        return 'Sync workouts from Hevy.';
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
            'coach_enabled' => [
                'type' => 'boolean',
                'label' => 'Enable Auto-Coach',
                'description' => 'Automatically analyze and update routines',
                'default' => false,
            ],
            'coach_schedule_times' => [
                'type' => 'array',
                'label' => 'Coach Schedule Times (HH:mm)',
                'description' => 'When to run auto-coach (e.g., ["18:00"])',
                'default' => ['18:00'],
            ],
            'coach_schedule_timezone' => [
                'type' => 'string',
                'label' => 'Coach Timezone',
                'description' => 'Timezone for scheduled coaching',
                'default' => 'UTC',
            ],
            'goal_reps' => [
                'type' => 'integer',
                'label' => 'Target Rep Range',
                'description' => 'Goal reps for progression decisions',
                'default' => 12,
                'min' => 1,
                'max' => 30,
            ],
            'progression_rpe_trigger' => [
                'type' => 'number',
                'label' => 'RPE Trigger for Weight Increase',
                'description' => 'Increase weight when RPE is at or below this',
                'default' => 9.0,
                'min' => 1.0,
                'max' => 10.0,
                'step' => 0.5,
            ],
            'weight_increment_kg' => [
                'type' => 'number',
                'label' => 'Weight Increment (kg)',
                'description' => 'Default weight increment amount',
                'default' => 5.0,
                'step' => 0.5,
            ],
            'deload_percentage' => [
                'type' => 'number',
                'label' => 'Deload Percentage',
                'description' => 'Percentage of weight to use for deload',
                'default' => 90.0,
                'min' => 50.0,
                'max' => 95.0,
                'step' => 5.0,
            ],
            'analysis_window_days' => [
                'type' => 'integer',
                'label' => 'Analysis Window (days)',
                'description' => 'How many days of workouts to analyze',
                'default' => 7,
                'min' => 1,
                'max' => 30,
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

    public static function getIcon(): string
    {
        return 'fas.dumbbell';
    }

    public static function getAccentColor(): string
    {
        return 'warning';
    }

    public static function getDomain(): string
    {
        return 'health';
    }

    public static function supportsMigration(): bool
    {
        return false;
    }

    public static function getActionTypes(): array
    {
        return [
            'completed_workout' => [
                'icon' => 'fas.dumbbell',
                'display_name' => 'Completed Workout',
                'description' => 'A workout session that has been completed in Hevy',
                'display_with_object' => true,
                'value_unit' => 'kcal',
                'hidden' => false,
            ],
            'had_coach_recommendation' => [
                'icon' => 'fas.robot',
                'display_name' => 'Coach Recommendation',
                'description' => 'Generated progression recommendation from fitness coach',
                'display_with_object' => false,
                'value_unit' => null,
                'hidden' => false,
            ],
        ];
    }

    public static function getBlockTypes(): array
    {
        return [
            'exercise' => [
                'icon' => 'fas.bolt',
                'display_name' => 'Exercise',
                'description' => 'A specific exercise performed during a workout',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
            'exercise_summary' => [
                'icon' => 'fas.chart-simple',
                'display_name' => 'Exercise Summary',
                'description' => 'Summary statistics for an exercise in a workout',
                'display_with_object' => true,
                'value_unit' => 'kg',
                'hidden' => false,
            ],
            'coach_recommendation' => [
                'icon' => 'fas.lightbulb',
                'display_name' => 'Coach Recommendation',
                'description' => 'Progression recommendation from fitness coach',
                'display_with_object' => false,
                'value_unit' => null,
                'hidden' => false,
            ],
        ];
    }

    public static function getObjectTypes(): array
    {
        return [
            'hevy_workout' => [
                'icon' => 'fas.dumbbell',
                'display_name' => 'Hevy Workout',
                'description' => 'A workout from Hevy app',
                'hidden' => false,
            ],
            'hevy_user' => [
                'icon' => 'fas.user',
                'display_name' => 'Hevy User',
                'description' => 'A Hevy user account',
                'hidden' => false,
            ],
            'hevy_routine' => [
                'icon' => 'fas.list-check',
                'display_name' => 'Hevy Routine',
                'description' => 'A workout routine template from Hevy',
                'hidden' => false,
            ],
            'hevy_exercise_template' => [
                'icon' => 'fas.file-lines',
                'display_name' => 'Exercise Template',
                'description' => 'An exercise configuration from a routine',
                'hidden' => false,
            ],
        ];
    }

    public static function getServiceType(): string
    {
        return 'apikey';
    }

    /**
     * API key integrations use polling, not staleness checking
     */
    public static function getTimeUntilStaleMinutes(): ?int
    {
        return null;
    }

    /**
     * Get effects that users can trigger.
     */
    public static function getEffects(): array
    {
        return [
            'analyze_progression' => [
                'title' => 'Analyze Workout Progression',
                'description' => 'Review recent workouts and generate progression recommendations',
                'icon' => 'fas.chart-line',
                'jobClass' => HevyAnalyzeProgressionEffect::class,
                'queue' => 'effects',
                'requiresConfirmation' => false,
                'successMessage' => 'Analyzing workouts...',
            ],
            'update_routine' => [
                'title' => 'Update Hevy Routine',
                'description' => 'Apply progression recommendations to your Hevy routine',
                'icon' => 'fas.arrow-up',
                'jobClass' => HevyUpdateRoutineEffect::class,
                'queue' => 'effects',
                'requiresConfirmation' => true,
                'confirmationMessage' => 'This will update your Hevy routine with new targets. Continue?',
                'successMessage' => 'Routine update in progress...',
            ],
            'auto_coach' => [
                'title' => 'Auto-Coach Routine',
                'description' => 'Analyze and update routine automatically',
                'icon' => 'fas.robot',
                'jobClass' => HevyAutoCoachEffect::class,
                'queue' => 'effects',
                'requiresConfirmation' => true,
                'confirmationMessage' => 'This will analyze workouts and update your routine. Continue?',
                'successMessage' => 'Auto-coaching in progress...',
            ],
        ];
    }

    /**
     * Register task pipeline tasks for automatic coach execution
     */
    public static function getTaskDefinitions(): array
    {
        return [
            new TaskDefinition(
                key: 'hevy_auto_coach',
                name: 'Hevy Auto Coach',
                description: 'Automatically analyze workouts and update routines with progressive overload recommendations',
                jobClass: HevyAutoCoachEffect::class,
                appliesTo: ['event'],
                conditions: [
                    'service' => 'hevy',
                    'action' => 'completed_workout',
                ],
                dependencies: [],
                queue: 'effects',
                priority: 50,
                runOnCreate: true,
                runOnUpdate: false,
                shouldRun: function ($model) {
                    // Get the integration
                    $integration = $model->integration;

                    if (! $integration) {
                        return false;
                    }

                    // Check if coach is enabled
                    $config = $integration->configuration ?? [];
                    if (! ($config['coach_enabled'] ?? false)) {
                        return false;
                    }

                    // Check if we've already successfully run coach today for this integration
                    // by looking for recent coach_recommendation blocks (not cache)
                    // to avoid running multiple times if multiple workouts are logged
                    $hasRecentRecommendation = Block::whereHas('event', function ($q) use ($integration) {
                        $q->where('integration_id', $integration->id)
                            ->where('action', 'had_coach_recommendation')
                            ->where('created_at', '>=', now()->startOfDay());
                    })
                        ->where('block_type', 'coach_recommendation')
                        ->exists();

                    if ($hasRecentRecommendation) {
                        return false;
                    }

                    return true;
                },
            ),
        ];
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

    public function createInstance(IntegrationGroup $group, string $instanceType, array $initialConfig = [], bool $withMigration = false): Integration
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
        $config = $integration->configuration ?? [];
        // Incremental window: default 3 days to reduce payload, configurable via days_back
        $incrementalDays = max(1, (int) ($config['incremental_days'] ?? 3));
        $startDate = now()->subDays($incrementalDays)->toDateString();
        $endDate = now()->toDateString();

        // Check if we should perform a sweep for this integration
        $this->performSweepIfNeeded($integration);

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

    /**
     * Pull routine data from Hevy API.
     */
    public function pullRoutineData(Integration $integration): array
    {
        Log::info('Hevy: Fetching routines', [
            'integration_id' => $integration->id,
        ]);

        try {
            $allRoutines = [];
            $page = 1;
            $pageCount = 1;

            // Follow pagination with a sensible upper bound
            $maxPages = 0;
            do {
                if ($maxPages++ >= 10) {
                    Log::warning('Hevy: Routine pagination capped at 10 pages', [
                        'integration_id' => $integration->id,
                    ]);
                    break;
                }

                $query = http_build_query([
                    'page' => $page,
                    'limit' => 100,
                ]);

                $endpoint = '/v1/routines?' . $query;
                $json = $this->getJson($endpoint, $integration);

                $routines = $json['routines'] ?? $json['data'] ?? [];
                $allRoutines = array_merge($allRoutines, $routines);

                $pageCount = (int) ($json['page_count'] ?? 1);
                $currentPage = (int) ($json['page'] ?? 1);

                Log::info('Hevy: Fetched routine page', [
                    'integration_id' => $integration->id,
                    'page' => $currentPage,
                    'page_count' => $pageCount,
                    'routines_in_page' => count($routines),
                    'total_routines' => count($allRoutines),
                ]);

                $page++;
            } while ($page <= $pageCount);

            Log::info('Hevy: Completed fetching all routine pages', [
                'integration_id' => $integration->id,
                'total_routines' => count($allRoutines),
                'pages_fetched' => $page - 1,
            ]);

            return [
                'routines' => $allRoutines,
                'page' => 1,
                'page_count' => 1,
            ];
        } catch (Throwable $e) {
            Log::error('Hevy: Routine fetch failed', [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Update a routine on Hevy API.
     */
    public function updateRoutine(Integration $integration, string $routineId, array $updates): array
    {
        $apiKey = (string) ($integration->configuration['api_key'] ?? $this->apiKey ?? '');
        $url = $this->baseUrl . '/v1/routines/' . $routineId;

        log_integration_api_request('hevy', 'PUT', "/v1/routines/{$routineId}", ['api-key' => '***'], $updates, $integration->id);

        $response = Http::withHeaders(['api-key' => $apiKey])
            ->put($url, $updates);

        log_integration_api_response('hevy', 'PUT', "/v1/routines/{$routineId}", $response->status(), $response->body(), $response->headers(), $integration->id);

        if (! $response->successful()) {
            throw new RuntimeException('Hevy routine update failed: ' . $response->status() . ' - ' . $response->body());
        }

        return $response->json() ?? [];
    }

    /**
     * Pull workout data for pull jobs
     */
    public function pullWorkoutData(Integration $integration): array
    {
        $daysBack = (int) ($integration->configuration['days_back'] ?? 14);
        $startDate = now()->subDays($daysBack)->toDateString();
        $endDate = now()->toDateString();

        Log::info('Hevy: Fetching workouts', [
            'integration_id' => $integration->id,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        try {
            $allWorkouts = [];
            $page = 1;
            $pageCount = 1;

            // Follow pagination with a sensible upper bound to avoid long-running jobs
            $maxPages = 0;
            do {
                if ($maxPages++ >= 20) {
                    Log::warning('Hevy: Workout pagination capped at 20 pages', [
                        'integration_id' => $integration->id,
                    ]);
                    break;
                }

                $query = http_build_query([
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'limit' => 100,
                    'page' => $page,
                ]);

                $endpoint = '/v1/workouts?' . $query;
                $json = $this->getJson($endpoint, $integration);

                $workouts = $json['workouts'] ?? $json['data'] ?? [];
                $allWorkouts = array_merge($allWorkouts, $workouts);

                $pageCount = (int) ($json['page_count'] ?? 1);
                $currentPage = (int) ($json['page'] ?? 1);

                Log::info('Hevy: Fetched workout page', [
                    'integration_id' => $integration->id,
                    'page' => $currentPage,
                    'page_count' => $pageCount,
                    'workouts_in_page' => count($workouts),
                    'total_workouts' => count($allWorkouts),
                ]);

                $page++;
            } while ($page <= $pageCount);

            Log::info('Hevy: Completed fetching all workout pages', [
                'integration_id' => $integration->id,
                'total_workouts' => count($allWorkouts),
                'pages_fetched' => $page - 1,
            ]);

            // Return in the same format as the API response
            return [
                'workouts' => $allWorkouts,
                'page' => 1,
                'page_count' => 1,
            ];
        } catch (Throwable $e) {
            Log::error('Hevy: Workout fetch failed', [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
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

    public function createWorkoutEvent(Integration $integration, array $workout): void
    {
        $workoutId = (string) (Arr::get($workout, 'id') ?? md5(json_encode([$integration->id, Arr::get($workout, 'start_time'), Arr::get($workout, 'title')])));
        $startIso = Arr::get($workout, 'start_time') ?? Arr::get($workout, 'date') ?? now()->toIso8601String();
        $endIso = Arr::get($workout, 'end_time');
        $title = (string) (Arr::get($workout, 'title') ?? 'Workout');

        // Calculate duration from timestamps if available
        $durationSec = 0;
        if ($startIso && $endIso) {
            try {
                $start = Carbon::parse($startIso);
                $end = Carbon::parse($endIso);
                $durationSec = (int) $end->diffInSeconds($start);
            } catch (Exception $e) {
                // Fallback to 0 if parsing fails
                $durationSec = 0;
            }
        }

        $sourceId = "hevy_workout_{$integration->id}_{$workoutId}";
        $exists = Event::where('source_id', $sourceId)->where('integration_id', $integration->id)->first();
        if ($exists) {
            return;
        }

        $actor = $this->ensureUserProfile($integration);

        // Use workout ID + title as discriminator (not time which would create duplicates)
        $target = EventObject::firstOrCreate([
            'user_id' => $integration->user_id,
            'concept' => 'workout',
            'type' => 'hevy_workout',
            'title' => $title . ' (' . substr($workoutId, 0, 8) . ')',
        ], [
            'time' => $startIso,
            'content' => Arr::get($workout, 'description') ?? 'Hevy workout',
            'url' => Arr::get($workout, 'url'),
        ]);

        // Update metadata if workout details changed
        $target->update(['metadata' => $workout]);

        // Calculate total volume from all exercises and determine weight unit
        $exercises = Arr::get($workout, 'exercises', []);
        $totalVolume = 0.0;
        $workoutWeightUnit = null;

        foreach ($exercises as $exercise) {
            $sets = Arr::get($exercise, 'sets', []);
            foreach ($sets as $set) {
                $reps = (int) (Arr::get($set, 'reps', 0));
                // Try weight_kg first, then weight_lb
                $weight = (float) (Arr::get($set, 'weight_kg') ?? Arr::get($set, 'weight_lb', 0));
                $totalVolume += ($weight * max(0, $reps));

                // Infer unit from first set that has a weight
                if ($workoutWeightUnit === null && $weight > 0) {
                    $workoutWeightUnit = isset($set['weight_kg']) ? 'kg' : (isset($set['weight_lb']) ? 'lb' : null);
                }
            }
        }

        // Fallback to config if no weight unit found
        if ($workoutWeightUnit === null) {
            $workoutWeightUnit = $integration->configuration['units'] ?? 'kg';
        }

        [$encVolume, $volMult] = $this->encodeNumericValue($totalVolume);
        $event = Event::create([
            'source_id' => $sourceId,
            'time' => $startIso,
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'service' => 'hevy',
            'domain' => self::getDomain(),
            'action' => 'completed_workout',
            'value' => $encVolume,
            'value_multiplier' => $volMult,
            'value_unit' => $workoutWeightUnit,
            'event_metadata' => [
                'end' => $endIso,
                'duration_seconds' => $durationSec,
            ],
            'target_id' => $target->id,
        ]);

        // Create blocks per exercise set
        $includeExerciseSummary = in_array('enabled', ($integration->configuration['include_exercise_summary_blocks'] ?? ['enabled']), true);

        foreach ($exercises as $exercise) {
            $exerciseName = (string) (Arr::get($exercise, 'name') ?? Arr::get($exercise, 'title') ?? 'Exercise');
            $sets = Arr::get($exercise, 'sets', []);
            $exerciseVolume = 0.0;
            $exerciseUnit = null;

            foreach ($sets as $index => $set) {
                $reps = (int) (Arr::get($set, 'reps', 0));
                // Try weight_kg first, then weight_lb
                $weight = (float) (Arr::get($set, 'weight_kg') ?? Arr::get($set, 'weight_lb', 0));
                $rest = Arr::get($set, 'rest_seconds');
                $rpe = Arr::get($set, 'rpe');
                $setType = Arr::get($set, 'type');
                $setNum = $index + 1;

                // Infer unit from the actual field present
                $unit = isset($set['weight_kg']) ? 'kg' : (isset($set['weight_lb']) ? 'lb' : ($workoutWeightUnit ?? 'kg'));

                // Track exercise unit for summary
                if ($exerciseUnit === null && $weight > 0) {
                    $exerciseUnit = $unit;
                }

                $exerciseVolume += ($weight * max(0, $reps));

                [$encWeight, $weightMult] = $this->encodeNumericValue($weight);

                $metadata = [
                    'exercise_name' => $exerciseName,
                    'set_number' => $setNum,
                    'reps' => $reps,
                    'weight' => $weight,
                    'unit' => $unit,
                ];
                if ($setType !== null && $setType !== '') {
                    $metadata['type'] = $setType;
                }
                if ($rpe !== null && $rpe !== '') {
                    $metadata['rpe'] = $rpe;
                }
                if ($rest !== null && $rest !== '') {
                    $metadata['rest_seconds'] = $rest;
                }

                $event->createBlock([
                    'block_type' => 'exercise',
                    'time' => $startIso,
                    'title' => $exerciseName . ' - Set ' . $setNum,
                    'metadata' => $metadata,
                    'url' => null,
                    'media_url' => null,
                    'value' => $encWeight,
                    'value_multiplier' => $weightMult,
                    'value_unit' => $unit,
                ]);
            }

            if ($includeExerciseSummary && $exerciseName !== '') {
                [$encExVol, $exVolMult] = $this->encodeNumericValue($exerciseVolume);
                $summaryUnit = $exerciseUnit ?? $workoutWeightUnit ?? 'kg';
                $event->createBlock([
                    'block_type' => 'exercise_summary',
                    'time' => $startIso,
                    'title' => $exerciseName . ' - Total Volume',
                    'metadata' => [
                        'exercise_name' => $exerciseName,
                        'total_volume' => $exerciseVolume,
                        'volume_formula' => 'weight x reps',
                        'sets_count' => count($sets),
                        'unit' => $summaryUnit,
                    ],
                    'value' => $encExVol,
                    'value_multiplier' => $exVolMult,
                    'value_unit' => $summaryUnit,
                ]);
            }
        }
    }

    /**
     * Perform a sweep if needed for any instance type
     */
    protected function performSweepIfNeeded(Integration $integration): void
    {
        $config = $integration->configuration ?? [];
        $lastSweepAt = isset($config['hevy_last_sweep_at']) ? Carbon::parse($config['hevy_last_sweep_at']) : null;
        $doSweep = ! $lastSweepAt || $lastSweepAt->lt(now()->subDays(6));

        if ($doSweep) {
            Log::info('Hevy sweep triggered', [
                'integration_id' => $integration->id,
                'instance_type' => $integration->instance_type,
                'last_sweep_at' => $lastSweepAt?->toIso8601String(),
            ]);

            // Perform sweep for all data types
            $this->performDataSweep($integration);

            // Update sweep timestamp
            $config['hevy_last_sweep_at'] = now()->toIso8601String();
            $integration->update(['configuration' => $config]);

            Log::info('Hevy sweep completed', [
                'integration_id' => $integration->id,
                'instance_type' => $integration->instance_type,
            ]);
        }
    }

    /**
     * Perform the actual data sweep across all Hevy data types
     */
    protected function performDataSweep(Integration $integration): void
    {
        $sweepStartDate = now()->subDays(30)->toDateString();
        $endDate = now()->toDateString();

        try {
            $sweepQuery = http_build_query([
                'start_date' => $sweepStartDate,
                'end_date' => $endDate,
                'limit' => 100,
            ]);
            $sweepEndpoint = '/v1/workouts?' . $sweepQuery;
            $sweepJson = $this->getJson($sweepEndpoint, $integration);

            $sweepItems = [];
            if (isset($sweepJson['data']) && is_array($sweepJson['data'])) {
                $sweepItems = $sweepJson['data'];
            } elseif (isset($sweepJson['workouts']) && is_array($sweepJson['workouts'])) {
                $sweepItems = $sweepJson['workouts'];
            } elseif (is_array($sweepJson) && array_is_list($sweepJson)) {
                $sweepItems = $sweepJson;
            }

            $processedCount = 0;
            foreach ($sweepItems as $workout) {
                if (is_array($workout)) {
                    $this->createWorkoutEvent($integration, $workout);
                    $processedCount++;
                }
            }

            Log::info('Hevy data sweep completed successfully', [
                'integration_id' => $integration->id,
                'workouts_count' => $processedCount,
            ]);
        } catch (Throwable $e) {
            Log::error('Hevy data sweep failed', [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Simple HTTP helper using API key authentication.
     */
    protected function getJson(string $endpoint, Integration $integration): array
    {
        $apiKey = (string) ($integration->configuration['api_key'] ?? $this->apiKey ?? '');
        // In tests we allow empty; in production recommend providing api key
        $url = Str::startsWith($endpoint, '/') ? $this->baseUrl . $endpoint : $this->baseUrl . '/' . ltrim($endpoint, '/');

        $headers = ['api-key' => $apiKey];

        // Log the API request
        log_integration_api_request(
            static::getIdentifier(),
            'GET',
            $endpoint,
            $headers,
            [],
            $integration->id
        );

        $response = Http::withHeaders($headers)->get($url);

        // Log the API response
        log_integration_api_response(
            static::getIdentifier(),
            'GET',
            $endpoint,
            $response->status(),
            $response->body(),
            $response->headers(),
            $integration->id
        );

        if (! $response->successful()) {
            throw new RuntimeException('Hevy API request failed with status ' . $response->status());
        }

        return $response->json() ?? [];
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

        // Use firstOrCreate to avoid updating 'time' on every call
        $user = EventObject::firstOrCreate([
            'user_id' => $integration->user_id,
            'concept' => 'user',
            'type' => 'hevy_user',
            'title' => $title,
        ], [
            'integration_id' => $integration->id,
            'time' => now(),
            'content' => 'Hevy user account',
            'url' => null,
            'media_url' => null,
        ]);

        // Only update metadata if provided
        if (is_array($profile) && ! empty($profile)) {
            $user->update(['metadata' => $profile]);
        }

        return $user;
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
