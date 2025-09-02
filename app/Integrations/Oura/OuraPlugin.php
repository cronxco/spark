<?php

namespace App\Integrations\Oura;

use App\Integrations\Base\OAuthPlugin;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;
use Throwable;

class OuraPlugin extends OAuthPlugin
{
    protected string $baseUrl = 'https://api.ouraring.com/v2';

    protected string $authUrl = 'https://cloud.ouraring.com';

    protected string $clientId;

    protected string $clientSecret;

    protected string $redirectUri;

    public function __construct()
    {
        $this->clientId = config('services.oura.client_id') ?? '';
        $this->clientSecret = config('services.oura.client_secret') ?? '';
        $this->redirectUri = config('services.oura.redirect') ?? route('integrations.oauth.callback', ['service' => 'oura']);

        if (app()->environment() !== 'testing' && (empty($this->clientId) || empty($this->clientSecret))) {
            throw new InvalidArgumentException('Oura OAuth credentials are not configured');
        }
    }

    public static function getIcon(): string
    {
        return 'o-heart';
    }

    public static function getAccentColor(): string
    {
        return 'primary';
    }

    public static function getDomain(): string
    {
        return 'health';
    }

    public static function getActionTypes(): array
    {
        return [
            'slept_for' => [
                'icon' => 'o-moon',
                'display_name' => 'Sleep',
                'description' => 'Sleep duration and quality data',
                'display_with_object' => true,
                'value_unit' => 'hours',
                'hidden' => false,
            ],
            'had_heart_rate' => [
                'icon' => 'o-heart',
                'display_name' => 'Heart Rate',
                'description' => 'Heart rate measurement data',
                'display_with_object' => true,
                'value_unit' => 'bpm',
                'hidden' => false,
            ],
            'did_workout' => [
                'icon' => 'o-fire',
                'display_name' => 'Workout',
                'description' => 'Workout activity data',
                'display_with_object' => true,
                'value_unit' => 'calories',
                'hidden' => false,
            ],
            'had_mindfulness_session' => [
                'icon' => 'o-sparkles',
                'display_name' => 'Mindfulness Session',
                'description' => 'Mindfulness or meditation session',
                'display_with_object' => true,
                'value_unit' => 'minutes',
                'hidden' => false,
            ],
            'had_oura_tag' => [
                'icon' => 'o-tag',
                'display_name' => 'Oura Tag',
                'description' => 'User-defined tag for the day',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
        ];
    }

    public static function getBlockTypes(): array
    {
        return [
            'sleep_stages' => [
                'icon' => 'o-clock',
                'display_name' => 'Sleep Stages',
                'description' => 'Sleep stage duration information',
                'display_with_object' => true,
                'value_unit' => 'minutes',
                'hidden' => false,
            ],
            'heart_rate' => [
                'icon' => 'o-heart',
                'display_name' => 'Heart Rate',
                'description' => 'Heart rate data from Oura Ring',
                'display_with_object' => true,
                'value_unit' => 'bpm',
                'hidden' => false,
            ],
            'tag' => [
                'icon' => 'o-tag',
                'display_name' => 'Tag',
                'description' => 'Tag information from Oura Ring',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
            'workout' => [
                'icon' => 'o-fire',
                'display_name' => 'Workout',
                'description' => 'Workout details from Oura Ring',
                'display_with_object' => true,
                'value_unit' => 'calories',
                'hidden' => false,
            ],
        ];
    }

    public static function getObjectTypes(): array
    {
        return [
            'oura_user' => [
                'icon' => 'o-user',
                'display_name' => 'Oura User',
                'description' => 'An Oura Ring user account',
                'hidden' => false,
            ],
            'oura_sleep_record' => [
                'icon' => 'o-moon',
                'display_name' => 'Oura Sleep Record',
                'description' => 'A sleep record from Oura Ring',
                'hidden' => false,
            ],
            'heartrate_series' => [
                'icon' => 'o-heart',
                'display_name' => 'Heart Rate Series',
                'description' => 'A series of heart rate measurements',
                'hidden' => false,
            ],
            'oura_daily_{$kind}' => [
                'icon' => 'o-calendar',
                'display_name' => 'Oura Daily Record',
                'description' => 'A daily record from Oura Ring',
                'hidden' => false,
            ],
            'oura_tag' => [
                'icon' => 'o-tag',
                'display_name' => 'Oura Tag',
                'description' => 'A tag from Oura Ring',
                'hidden' => false,
            ],
        ];
    }

    public static function getIdentifier(): string
    {
        return 'oura';
    }

    public static function getDisplayName(): string
    {
        return 'Oura';
    }

    public static function getDescription(): string
    {
        return 'Connect your Oura Ring to track daily activity, sleep, readiness, resilience, stress, workouts, sessions, tags, and time-series metrics like heart rate and SpO2.';
    }

    public static function getConfigurationSchema(): array
    {
        return [
            'update_frequency_minutes' => [
                'type' => 'number',
                'label' => 'Update frequency (minutes)',
                'default' => 60,
                'min' => 5,
                'max' => 1440,
            ],
            'days_back' => [
                'type' => 'number',
                'label' => 'Days back to fetch on each run',
                'default' => 7,
                'min' => 1,
                'max' => 30,
            ],
        ];
    }

    public static function getInstanceTypes(): array
    {
        return [
            'activity' => [
                'label' => 'Daily Activity',
                'schema' => self::getConfigurationSchema(),
            ],
            'sleep' => [
                'label' => 'Daily Sleep',
                'schema' => self::getConfigurationSchema(),
            ],
            'sleep_records' => [
                'label' => 'Sleep Records',
                'schema' => self::getConfigurationSchema(),
            ],
            'readiness' => [
                'label' => 'Daily Readiness',
                'schema' => self::getConfigurationSchema(),
            ],
            'resilience' => [
                'label' => 'Daily Resilience',
                'schema' => self::getConfigurationSchema(),
            ],
            'stress' => [
                'label' => 'Daily Stress',
                'schema' => self::getConfigurationSchema(),
            ],
            'workouts' => [
                'label' => 'Workouts',
                'schema' => self::getConfigurationSchema(),
            ],
            'sessions' => [
                'label' => 'Sessions',
                'schema' => self::getConfigurationSchema(),
            ],
            'tags' => [
                'label' => 'Tags',
                'schema' => self::getConfigurationSchema(),
            ],
            'heartrate' => [
                'label' => 'Heart Rate (time series)',
                'schema' => self::getConfigurationSchema(),
            ],
            'spo2' => [
                'label' => 'Daily SpO2',
                'schema' => self::getConfigurationSchema(),
            ],
        ];
    }

    public function getOAuthUrl(IntegrationGroup $group): string
    {
        // Use cloud.ouraring.com for authorization endpoint
        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->generateCodeChallenge($codeVerifier);

        $csrfToken = Str::random(32);
        $sessionKey = 'oauth_csrf_' . session_id() . '_' . $group->id;
        Session::put($sessionKey, $csrfToken);

        $state = encrypt([
            'group_id' => $group->id,
            'user_id' => $group->user_id,
            'csrf_token' => $csrfToken,
            'code_verifier' => $codeVerifier,
        ]);

        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => $this->getRequiredScopes(),
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];

        return $this->authUrl . '/oauth/authorize?' . http_build_query($params);
    }

    public function handleOAuthCallback(Request $request, IntegrationGroup $group): void
    {
        $error = $request->get('error');
        if ($error) {
            Log::error('Oura OAuth callback returned error', [
                'group_id' => $group->id,
                'error' => $error,
                'error_description' => $request->get('error_description'),
            ]);
            throw new Exception('Oura authorization failed: ' . $error);
        }

        $code = $request->get('code');
        if (! $code) {
            Log::error('Oura OAuth callback missing authorization code', [
                'group_id' => $group->id,
            ]);
            throw new Exception('Invalid OAuth callback: missing authorization code');
        }

        $state = $request->get('state');
        if (! $state) {
            Log::error('Oura OAuth callback missing state parameter', [
                'group_id' => $group->id,
            ]);
            throw new Exception('Invalid OAuth callback: missing state parameter');
        }

        try {
            $stateData = decrypt($state);
        } catch (Throwable $e) {
            Log::error('Oura OAuth state decryption failed', [
                'group_id' => $group->id,
                'exception' => $e->getMessage(),
            ]);
            throw new Exception('Invalid OAuth callback: state decryption failed');
        }

        if ((string) ($stateData['group_id'] ?? '') !== (string) $group->id) {
            throw new Exception('Invalid state parameter');
        }

        if (! isset($stateData['csrf_token']) || ! $this->validateCsrfToken($stateData['csrf_token'], $group)) {
            throw new Exception('Invalid CSRF token');
        }

        $codeVerifier = $stateData['code_verifier'] ?? null;
        if (! $codeVerifier) {
            throw new Exception('Missing code verifier');
        }

        // Log the API request
        $this->logApiRequest('POST', '/oauth/token', [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], [
            'client_id' => $this->clientId,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri,
            'code_verifier' => '[REDACTED]', // PKCE code verifier
        ]);

        // Exchange code for tokens with PKCE against api.ouraring.com
        $hub = SentrySdk::getCurrentHub();
        $parentSpan = $hub->getSpan();
        $span = $parentSpan?->startChild((new SpanContext)->setOp('http.client')->setDescription('POST https://api.ouraring.com/oauth/token'));
        $response = Http::asForm()->post('https://api.ouraring.com/oauth/token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri,
            'code_verifier' => $codeVerifier,
        ]);
        $span?->finish();

        // Log the API response
        $this->logApiResponse('POST', '/oauth/token', $response->status(), $response->body(), $response->headers());

        if (! $response->successful()) {
            Log::error('Oura token exchange failed', [
                'response' => $response->body(),
                'status' => $response->status(),
            ]);
            throw new Exception('Failed to exchange code for tokens: ' . $response->body());
        }

        $tokenData = $response->json();

        // Update group with tokens
        $group->update([
            'access_token' => $tokenData['access_token'] ?? null,
            'refresh_token' => $tokenData['refresh_token'] ?? null,
            'expiry' => isset($tokenData['expires_in']) ? now()->addSeconds($tokenData['expires_in']) : null,
        ]);

        $this->fetchAccountInfoForGroup($group);
    }

    public function fetchData(Integration $integration): void
    {
        $type = $integration->instance_type ?? 'activity';
        $daysBack = (int) ($integration->configuration['days_back'] ?? 7);
        $startDate = now()->subDays($daysBack)->toDateString();
        $endDate = now()->toDateString();

        if ($type === 'sleep') {
            $this->fetchDailySleep($integration, $startDate, $endDate);

            return;
        }

        if ($type === 'sleep_records') {
            $this->fetchSleepRecords($integration, $startDate, $endDate);

            return;
        }

        if ($type === 'activity') {
            $this->fetchDailyActivity($integration, $startDate, $endDate);

            return;
        }

        if ($type === 'readiness') {
            $this->fetchDailyReadiness($integration, $startDate, $endDate);

            return;
        }

        if ($type === 'resilience') {
            $this->fetchDailyResilience($integration, $startDate, $endDate);

            return;
        }

        if ($type === 'stress') {
            $this->fetchDailyStress($integration, $startDate, $endDate);

            return;
        }

        if ($type === 'workouts') {
            $this->fetchWorkouts($integration, $startDate, $endDate);

            return;
        }

        if ($type === 'sessions') {
            $this->fetchSessions($integration, $startDate, $endDate);

            return;
        }

        if ($type === 'tags') {
            $this->fetchTags($integration, $startDate, $endDate);

            return;
        }

        if ($type === 'heartrate') {
            $this->fetchHeartRateSeries($integration, now()->subDays($daysBack)->toIso8601String(), now()->toIso8601String());

            return;
        }

        if ($type === 'spo2') {
            $this->fetchDailySpO2($integration, $startDate, $endDate);

            return;
        }
    }

    public function convertData(array $externalData, Integration $integration): array
    {
        // Not used for Oura (we directly create events), but required by interface
        return [];
    }

    // Public helper for migration processing: converts items into events per instance type
    public function processOuraMigrationItems(Integration $integration, string $instanceType, array $items): void
    {
        switch ($instanceType) {
            case 'activity':
                foreach ($items as $item) {
                    $this->createDailyRecordEvent($integration, 'activity', $item, [
                        'score_field' => 'score',
                        'contributors_field' => 'contributors',
                        'title' => 'Activity',
                        'value_unit' => 'percent',
                        'contributors_value_unit' => 'percent',
                        'details_fields' => ['steps', 'cal_total', 'equivalent_walking_distance', 'target_calories', 'non_wear_time'],
                    ]);
                }
                break;
            case 'sleep_records':
                foreach ($items as $item) {
                    $this->createSleepRecordFromItem($integration, $item);
                }
                break;
            case 'sleep':
                foreach ($items as $item) {
                    $this->createDailyRecordEvent($integration, 'sleep', $item, [
                        'score_field' => 'score',
                        'contributors_field' => 'contributors',
                        'title' => 'Sleep',
                        'value_unit' => 'percent',
                    ]);
                }
                break;
            case 'readiness':
                foreach ($items as $item) {
                    $this->createDailyRecordEvent($integration, 'readiness', $item, [
                        'score_field' => 'score',
                        'contributors_field' => 'contributors',
                        'title' => 'Readiness',
                        'value_unit' => 'percent',
                        'contributors_value_unit' => 'percent',
                    ]);
                }
                break;
            case 'resilience':
                foreach ($items as $item) {
                    $this->createDailyRecordEvent($integration, 'resilience', $item, [
                        'score_field' => 'resilience_score',
                        'contributors_field' => 'contributors',
                        'title' => 'Resilience',
                        'value_unit' => 'percent',
                        'contributors_value_unit' => 'percent',
                    ]);
                }
                break;
            case 'stress':
                foreach ($items as $item) {
                    $this->createDailyRecordEvent($integration, 'stress', $item, [
                        'score_field' => 'stress_score',
                        'contributors_field' => 'contributors',
                        'title' => 'Stress',
                        'value_unit' => 'percent',
                        'contributors_value_unit' => 'percent',
                    ]);
                }
                break;
            case 'spo2':
                foreach ($items as $item) {
                    $this->createDailyRecordEvent($integration, 'spo2', $item, [
                        'score_field' => 'spo2_average',
                        'contributors_field' => null,
                        'title' => 'SpO2',
                        'value_unit' => 'percent',
                    ]);
                }
                break;
            case 'workouts':
                foreach ($items as $item) {
                    $this->createWorkoutEvent($integration, $item);
                }
                break;
            case 'sessions':
                foreach ($items as $item) {
                    $this->createSessionEvent($integration, $item);
                }
                break;
            case 'tags':
                foreach ($items as $item) {
                    $this->createTagEvent($integration, $item);
                }
                break;
            default:
                // leave unsupported types to other paths
                break;
        }
    }

    /**
     * Public helper for migration: fetch a window for a given instance type with headers/status.
     * Cursor: start_date/end_date (Y-m-d) or start_datetime/end_datetime (ISO8601 for heartrate)
     * Returns array with keys: ok, status, headers, items
     */
    public function fetchWindowWithMeta(Integration $integration, string $instanceType, array $cursor): array
    {
        $endpoint = null;
        $query = [];
        if ($instanceType === 'heartrate') {
            $endpoint = '/usercollection/heartrate';
            $query = [
                'start_datetime' => $cursor['start_datetime'] ?? now()->subDays(6)->toIso8601String(),
                'end_datetime' => $cursor['end_datetime'] ?? now()->toIso8601String(),
            ];
        } else {
            $endpoint = match ($instanceType) {
                'activity' => '/usercollection/daily_activity',
                'sleep' => '/usercollection/daily_sleep',
                'sleep_records' => '/usercollection/sleep',
                'readiness' => '/usercollection/daily_readiness',
                'resilience' => '/usercollection/daily_resilience',
                'stress' => '/usercollection/daily_stress',
                'workouts' => '/usercollection/workout',
                'sessions' => '/usercollection/session',
                'tags' => '/usercollection/tag',
                'spo2' => '/usercollection/daily_spo2',
                default => null,
            };
            $query = [
                'start_date' => $cursor['start_date'] ?? now()->subDays(29)->toDateString(),
                'end_date' => $cursor['end_date'] ?? now()->toDateString(),
            ];
        }

        if (! $endpoint) {
            return [
                'ok' => false,
                'status' => 400,
                'headers' => [],
                'items' => [],
            ];
        }

        // Token handling like getJson, but we need headers/status
        $group = $integration->group;
        $token = $group?->access_token;
        if ($group && $group->expiry && $group->expiry->isPast()) {
            $this->refreshToken($group);
            $token = $group->access_token;
        }
        if (empty($token)) {
            return [
                'ok' => false,
                'status' => 401,
                'headers' => [],
                'items' => [],
            ];
        }

        // Log the API request
        $this->logApiRequest('GET', $endpoint, [
            'Authorization' => '[REDACTED]',
        ], $query, $integration->id);

        $hub = SentrySdk::getCurrentHub();
        $parentSpan = $hub->getSpan();
        $desc = 'GET ' . $this->baseUrl . $endpoint . (! empty($query) ? '?' . http_build_query($query) : '');
        $span = $parentSpan?->startChild((new SpanContext)->setOp('http.client')->setDescription($desc));
        $response = Http::withToken($token)->get($this->baseUrl . $endpoint, $query);
        $span?->finish();

        // Log the API response
        $this->logApiResponse('GET', $endpoint, $response->status(), $response->body(), $response->headers(), $integration->id);

        $ok = $response->successful();
        $status = $response->status();
        $headers = $response->headers();
        $json = $ok ? ($response->json() ?? []) : [];
        $items = $json['data'] ?? $json ?? [];

        if (! $ok) {
            Log::warning('Oura window fetch failed', [
                'endpoint' => $endpoint,
                'status' => $status,
                'response' => $response->body(),
            ]);
        }

        return [
            'ok' => $ok,
            'status' => $status,
            'headers' => $headers,
            'items' => is_array($items) ? $items : [],
        ];
    }

    /**
     * Log API request details for debugging
     */
    public function logApiRequest(string $method, string $endpoint, array $headers = [], array $data = [], ?string $integrationId = null): void
    {
        log_integration_api_request(
            static::getIdentifier(),
            $method,
            $endpoint,
            $this->sanitizeHeaders($headers),
            $this->sanitizeData($data),
            $integrationId ?: '',
            true // Use per-instance logging
        );
    }

    /**
     * Log API response details for debugging
     */
    public function logApiResponse(string $method, string $endpoint, int $statusCode, string $body, array $headers = [], ?string $integrationId = null): void
    {
        log_integration_api_response(
            static::getIdentifier(),
            $method,
            $endpoint,
            $statusCode,
            $this->sanitizeResponseBody($body),
            $this->sanitizeHeaders($headers),
            $integrationId ?: '',
            true // Use per-instance logging
        );
    }

    /**
     * HTTP helper that attaches Bearer token from the group and refreshes when needed.
     */
    /**
     * Get authentication headers for HTTP requests
     */
    public function authHeaders(Integration $integration): array
    {
        $group = $integration->group;
        $token = $group?->access_token;
        if ($group && $group->expiry && $group->expiry->isPast()) {
            $this->refreshToken($group);
            $token = $group->access_token;
        }

        if (empty($token)) {
            throw new Exception('Missing access token for authenticated request');
        }

        return [
            'Authorization' => 'Bearer ' . $token,
        ];
    }

    protected function getRequiredScopes(): string
    {
        return implode(' ', [
            'email',
            'personal',
            'daily',
            'heartrate',
            'workout',
            'tag',
            'session',
            'spo2',
            'stress',
            'resilience',
        ]);
    }

    protected function refreshToken(IntegrationGroup $group): void
    {
        // Log the API request
        $this->logApiRequest('POST', '/oauth/token', [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], [
            'client_id' => $this->clientId,
            'grant_type' => 'refresh_token',
        ]);

        $hub = SentrySdk::getCurrentHub();
        $parentSpan = $hub->getSpan();
        $span = $parentSpan?->startChild((new SpanContext)->setOp('http.client')->setDescription('POST https://api.ouraring.com/oauth/token'));
        $response = Http::asForm()->post('https://api.ouraring.com/oauth/token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $group->refresh_token,
            'grant_type' => 'refresh_token',
        ]);
        $span?->finish();

        // Log the API response
        $this->logApiResponse('POST', '/oauth/token', $response->status(), $response->body(), $response->headers());

        if (! $response->successful()) {
            throw new Exception('Failed to refresh token');
        }

        $tokenData = $response->json();

        $group->update([
            'access_token' => $tokenData['access_token'] ?? $group->access_token,
            'refresh_token' => $tokenData['refresh_token'] ?? $group->refresh_token,
            'expiry' => isset($tokenData['expires_in']) ? now()->addSeconds($tokenData['expires_in']) : null,
        ]);
    }

    protected function fetchAccountInfoForGroup(IntegrationGroup $group): void
    {
        // Create a temp Integration bound to the group to reuse token handling
        $temp = new Integration;
        $temp->setRelation('group', $group);
        $info = $this->getJson('/usercollection/personal_info', $temp);

        $group->update([
            'account_id' => Arr::get($info, 'data.0.user_id') ?? Arr::get($info, 'user_id') ?? Arr::get($info, 'email'),
        ]);
    }

    protected function getJson(string $endpoint, Integration $integration, array $query = []): array
    {
        $group = $integration->group;
        $token = $group?->access_token;
        if ($group && $group->expiry && $group->expiry->isPast()) {
            $this->refreshToken($group);
            $token = $group->access_token;
        }

        if (empty($token)) {
            throw new Exception('Missing access token for authenticated request');
        }

        // Log the API request
        $this->logApiRequest('GET', $endpoint, [
            'Authorization' => '[REDACTED]',
        ], $query, $integration->id);

        $hub = SentrySdk::getCurrentHub();
        $parentSpan = $hub->getSpan();
        $desc = 'GET ' . $this->baseUrl . $endpoint . (! empty($query) ? '?' . http_build_query($query) : '');
        $span = $parentSpan?->startChild((new SpanContext)->setOp('http.client')->setDescription($desc));
        $response = Http::withToken($token)->get($this->baseUrl . $endpoint, $query);
        $span?->finish();

        // Log the API response
        $this->logApiResponse('GET', $endpoint, $response->status(), $response->body(), $response->headers(), $integration->id);

        if (! $response->successful()) {
            Log::warning('Oura API request failed', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            return [];
        }

        return $response->json();
    }

    protected function createOrUpdateUser(Integration $integration, array $profile = []): EventObject
    {
        $title = $integration->name ?: 'Oura Account';

        return EventObject::updateOrCreate(
            [
                'user_id' => $integration->user_id,
                'concept' => 'user',
                'type' => 'oura_user',
                'title' => $title,
            ],
            [
                'integration_id' => $integration->id,
                'time' => now(),
                'content' => 'Oura account',
                'metadata' => $profile,
                'url' => null,
                'media_url' => null,
            ]
        );
    }

    protected function ensureUserProfile(Integration $integration): EventObject
    {
        $info = $this->getJson('/usercollection/personal_info', $integration);
        $data = Arr::first($info['data'] ?? []) ?? $info;
        $profile = [
            'user_id' => $integration->group?->account_id,
            'email' => Arr::get($data, 'email'),
            'age' => Arr::get($data, 'age'),
            'biological_sex' => Arr::get($data, 'biological_sex'),
            'weight' => Arr::get($data, 'weight'),
            'height' => Arr::get($data, 'height'),
            'dominant_hand' => Arr::get($data, 'dominant_hand'),
        ];

        return $this->createOrUpdateUser($integration, $profile);
    }

    protected function fetchDailySleep(Integration $integration, string $startDate, string $endDate): void
    {
        $this->ensureUserProfile($integration);
        $json = $this->getJson('/usercollection/daily_sleep', $integration, [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        $items = $json['data'] ?? [];
        foreach ($items as $item) {
            $this->createDailyRecordEvent($integration, 'sleep', $item, [
                'score_field' => 'score',
                'contributors_field' => 'contributors',
                'title' => 'Sleep',
                'value_unit' => 'percent',
                'contributors_value_unit' => 'percent',
            ]);
        }
    }

    protected function fetchSleepRecords(Integration $integration, string $startDate, string $endDate): void
    {
        $this->ensureUserProfile($integration);
        $json = $this->getJson('/usercollection/sleep', $integration, [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        $items = $json['data'] ?? [];
        foreach ($items as $item) {
            $start = Arr::get($item, 'bedtime_start');
            $end = Arr::get($item, 'bedtime_end');
            $day = $start ? Str::substr($start, 0, 10) : (Arr::get($item, 'day') ?? now()->toDateString());
            $id = Arr::get($item, 'id') ?? md5(json_encode([$day, Arr::get($item, 'duration', 0), Arr::get($item, 'total', 0)]));
            $sourceId = "oura_sleep_record_{$integration->id}_{$id}";
            $exists = Event::where('source_id', $sourceId)->where('integration_id', $integration->id)->first();
            if ($exists) {
                continue;
            }

            $actor = $this->ensureUserProfile($integration);
            $target = EventObject::updateOrCreate([
                'user_id' => $integration->user_id,
                'concept' => 'sleep',
                'type' => 'oura_sleep_record',
                'title' => 'Sleep Record',
            ], [
                'time' => $start ?? ($day . ' 00:00:00'),
                'content' => 'Detailed sleep record including stages and efficiency',
                'metadata' => $item,
            ]);

            $duration = (int) Arr::get($item, 'duration', 0);
            $efficiency = Arr::get($item, 'efficiency');
            $event = Event::create([
                'source_id' => $sourceId,
                'time' => $start ?? ($day . ' 00:00:00'),
                'integration_id' => $integration->id,
                'actor_id' => $actor->id,
                'service' => 'oura',
                'domain' => self::getDomain(),
                'action' => 'slept_for',
                'value' => $duration,
                'value_multiplier' => 1,
                'value_unit' => 'seconds',
                'event_metadata' => [
                    'end' => $end,
                    'efficiency' => $efficiency,
                ],
                'target_id' => $target->id,
            ]);

            $stages = Arr::get($item, 'sleep_stages', []);
            $stageMap = [
                'deep' => 'Deep Sleep',
                'light' => 'Light Sleep',
                'rem' => 'REM Sleep',
                'awake' => 'Awake Time',
            ];
            foreach (['deep', 'light', 'rem', 'awake'] as $stage) {
                $seconds = Arr::get($stages, $stage);
                if ($seconds === null) {
                    continue;
                }
                $event->blocks()->create(['block_type' => 'tag',

                    'time' => $event->time,
                    'integration_id' => $integration->id,
                    'title' => $stageMap[$stage] ?? Str::title($stage) . ' Sleep',
                    'metadata' => ['text' => 'Stage duration'],
                    'value' => (int) $seconds,
                    'value_multiplier' => 1,
                    'value_unit' => 'seconds',
                ]);
            }

            $hrAvg = Arr::get($item, 'average_heart_rate');
            if ($hrAvg !== null) {
                [$encodedHrAvg, $hrAvgMultiplier] = $this->encodeNumericValue($hrAvg);
                $event->blocks()->create(['block_type' => 'sleep_stages',

                    'time' => $event->time,
                    'integration_id' => $integration->id,
                    'title' => 'Average Heart Rate',
                    'metadata' => ['text' => 'Average sleeping heart rate'],
                    'value' => $encodedHrAvg,
                    'value_multiplier' => $hrAvgMultiplier,
                    'value_unit' => 'bpm',
                ]);
            }
        }
    }

    protected function fetchDailyActivity(Integration $integration, string $startDate, string $endDate): void
    {
        $this->ensureUserProfile($integration);
        $json = $this->getJson('/usercollection/daily_activity', $integration, [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        $items = $json['data'] ?? [];
        foreach ($items as $item) {
            $this->createDailyRecordEvent($integration, 'activity', $item, [
                'score_field' => 'score',
                'contributors_field' => 'contributors',
                'title' => 'Activity',
                'value_unit' => 'percent',
                'contributors_value_unit' => 'percent',
                'details_fields' => [
                    'steps', 'cal_total', 'equivalent_walking_distance', 'target_calories', 'non_wear_time',
                ],
            ]);
        }
    }

    protected function fetchDailyReadiness(Integration $integration, string $startDate, string $endDate): void
    {
        $this->ensureUserProfile($integration);
        $json = $this->getJson('/usercollection/daily_readiness', $integration, [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        $items = $json['data'] ?? [];
        foreach ($items as $item) {
            $this->createDailyRecordEvent($integration, 'readiness', $item, [
                'score_field' => 'score',
                'contributors_field' => 'contributors',
                'title' => 'Readiness',
                'value_unit' => 'percent',
                'contributors_value_unit' => 'percent',
            ]);
        }
    }

    protected function fetchDailyResilience(Integration $integration, string $startDate, string $endDate): void
    {
        $this->ensureUserProfile($integration);
        $json = $this->getJson('/usercollection/daily_resilience', $integration, [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        $items = $json['data'] ?? [];
        foreach ($items as $item) {
            $this->createDailyRecordEvent($integration, 'resilience', $item, [
                'score_field' => 'resilience_score',
                'contributors_field' => 'contributors',
                'title' => 'Resilience',
                'value_unit' => 'percent',
                'contributors_value_unit' => 'percent',
            ]);
        }
    }

    protected function fetchDailyStress(Integration $integration, string $startDate, string $endDate): void
    {
        $this->ensureUserProfile($integration);
        $json = $this->getJson('/usercollection/daily_stress', $integration, [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        $items = $json['data'] ?? [];
        foreach ($items as $item) {
            $this->createDailyRecordEvent($integration, 'stress', $item, [
                'score_field' => 'stress_score',
                'contributors_field' => 'contributors',
                'title' => 'Stress',
                'value_unit' => 'percent',
                'contributors_value_unit' => 'percent',
            ]);
        }
    }

    protected function fetchDailySpO2(Integration $integration, string $startDate, string $endDate): void
    {
        $this->ensureUserProfile($integration);
        $json = $this->getJson('/usercollection/daily_spo2', $integration, [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        $items = $json['data'] ?? [];
        foreach ($items as $item) {
            $this->createDailyRecordEvent($integration, 'spo2', $item, [
                'score_field' => 'spo2_average',
                'contributors_field' => null,
                'title' => 'SpO2',
                'value_unit' => 'percent',
            ]);
        }
    }

    protected function fetchWorkouts(Integration $integration, string $startDate, string $endDate): void
    {
        $this->ensureUserProfile($integration);
        $json = $this->getJson('/usercollection/workout', $integration, [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        $items = $json['data'] ?? [];
        foreach ($items as $item) {
            $this->createWorkoutEvent($integration, $item);
        }
    }

    protected function fetchSessions(Integration $integration, string $startDate, string $endDate): void
    {
        $this->ensureUserProfile($integration);
        $json = $this->getJson('/usercollection/session', $integration, [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        $items = $json['data'] ?? [];
        foreach ($items as $item) {
            $this->createSessionEvent($integration, $item);
        }
    }

    protected function fetchTags(Integration $integration, string $startDate, string $endDate): void
    {
        $this->ensureUserProfile($integration);
        $json = $this->getJson('/usercollection/tag', $integration, [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        $items = $json['data'] ?? [];
        foreach ($items as $item) {
            $this->createTagEvent($integration, $item);
        }
    }

    protected function fetchHeartRateSeries(Integration $integration, string $startIso, string $endIso): void
    {
        $this->ensureUserProfile($integration);
        $json = $this->getJson('/usercollection/heartrate', $integration, [
            'start_datetime' => $startIso,
            'end_datetime' => $endIso,
        ]);
        $items = $json['data'] ?? [];
        if (empty($items)) {
            return;
        }

        // Aggregate to one event per day with a few summary blocks
        $byDay = collect($items)->groupBy(fn ($p) => Str::substr($p['timestamp'] ?? $p['start_datetime'] ?? '', 0, 10));
        foreach ($byDay as $day => $points) {
            $min = (int) collect($points)->min('bpm');
            $max = (int) collect($points)->max('bpm');
            $avg = (float) collect($points)->avg('bpm');

            $sourceId = "oura_heartrate_{$integration->id}_{$day}";
            $exists = Event::where('source_id', $sourceId)->where('integration_id', $integration->id)->first();
            if ($exists) {
                continue;
            }

            $actor = $this->ensureUserProfile($integration);
            $target = EventObject::updateOrCreate([
                'user_id' => $integration->user_id,
                'concept' => 'metric',
                'type' => 'heartrate_series',
                'title' => 'Heart Rate',
            ], [
                'time' => now(),
                'content' => 'Heart rate time series',
                'metadata' => [
                    'interval' => 'irregular',
                ],
            ]);

            [$encodedAvg, $avgMultiplier] = $this->encodeNumericValue($avg);
            $event = Event::create([
                'source_id' => $sourceId,
                'time' => $day . ' 00:00:00',
                'integration_id' => $integration->id,
                'actor_id' => $actor->id,
                'service' => 'oura',
                'domain' => self::getDomain(),
                'action' => 'had_heart_rate',
                'value' => $encodedAvg,
                'value_multiplier' => $avgMultiplier,
                'value_unit' => 'bpm',
                'event_metadata' => [
                    'day' => $day,
                    'min_bpm' => $min,
                    'max_bpm' => $max,
                    'avg_bpm' => $avg,
                ],
                'target_id' => $target->id,
            ]);

            // Replace summary with separate min/max blocks
            [$encMin, $minMult] = $this->encodeNumericValue($min);
            $event->blocks()->create([
                'time' => $event->time,
                'integration_id' => $integration->id,
                'title' => 'Min Heart Rate',
                'metadata' => [],
                'value' => $encMin,
                'value_multiplier' => $minMult,
                'value_unit' => 'bpm',
            ]);

            [$encMax, $maxMult] = $this->encodeNumericValue($max);
            $event->blocks()->create([
                'time' => $event->time,
                'integration_id' => $integration->id,
                'title' => 'Max Heart Rate',
                'metadata' => [],
                'value' => $encMax,
                'value_multiplier' => $maxMult,
                'value_unit' => 'bpm',
            ]);

            $event->blocks()->create(['block_type' => 'heart_rate',

                'time' => $event->time,
                'integration_id' => $integration->id,
                'title' => 'Data Points',
                'metadata' => ['text' => 'Count of heart rate points collected for the day'],
                'value' => (int) $points->count(),
                'value_multiplier' => 1,
                'value_unit' => 'count',
            ]);
        }
    }

    /**
     * Generic daily record event creator with contributory blocks.
     * Options: score_field, contributors_field, title, value_unit, details_fields
     */
    protected function createDailyRecordEvent(Integration $integration, string $kind, array $item, array $options): void
    {
        $day = $item['day'] ?? $item['date'] ?? null;
        if (! $day) {
            return;
        }

        $sourceId = "oura_{$kind}_{$integration->id}_{$day}";
        $exists = Event::where('source_id', $sourceId)->where('integration_id', $integration->id)->first();
        if ($exists) {
            return;
        }

        $actor = $this->ensureUserProfile($integration);
        $target = EventObject::updateOrCreate([
            'user_id' => $integration->user_id,
            'concept' => 'metric',
            'type' => "oura_daily_{$kind}",
            'title' => $options['title'] ?? Str::title($kind),
        ], [
            'time' => $day . ' 00:00:00',
            'content' => ($options['title'] ?? Str::title($kind)) . ' daily summary',
            'metadata' => $item,
        ]);

        $scoreField = $options['score_field'] ?? 'score';
        $score = Arr::get($item, $scoreField);
        [$encodedScore, $scoreMultiplier] = $this->encodeNumericValue(is_numeric($score) ? (float) $score : null);

        // Action mapping for daily score-based instances
        $actionMap = [
            'activity' => 'had_activity_score',
            'sleep' => 'had_sleep_score',
            'readiness' => 'had_readiness_score',
            'resilience' => 'had_resilience_score',
            'stress' => 'had_stress_score',
            'spo2' => 'had_spo2',
        ];
        $action = $actionMap[$kind] ?? 'scored';

        $event = Event::create([
            'source_id' => $sourceId,
            'time' => $day . ' 00:00:00',
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'service' => 'oura',
            'domain' => self::getDomain(),
            'action' => $action,
            'value' => $encodedScore,
            'value_multiplier' => $scoreMultiplier,
            'value_unit' => $options['value_unit'] ?? 'score',
            'event_metadata' => [
                'day' => $day,
                'kind' => $kind,
            ],
            'target_id' => $target->id,
        ]);

        $contributorsField = $options['contributors_field'] ?? null;
        $contributors = $contributorsField ? Arr::get($item, $contributorsField, []) : [];
        foreach ($contributors as $name => $value) {
            [$encodedContrib, $contribMultiplier] = $this->encodeNumericValue(is_numeric($value) ? (float) $value : null);
            $event->blocks()->create([
                'time' => $event->time,
                'integration_id' => $integration->id,
                'title' => Str::title(str_replace('_', ' ', (string) $name)),
                'metadata' => ['text' => 'Contributor score'],
                'value' => $encodedContrib,
                'value_multiplier' => $contribMultiplier,
                'value_unit' => $options['contributors_value_unit'] ?? $options['value_unit'] ?? 'score',
            ]);
        }

        $detailsFields = $options['details_fields'] ?? [];
        if (! empty($detailsFields)) {
            $unitMap = [
                'steps' => 'count',
                'cal_total' => 'kcal',
                'equivalent_walking_distance' => 'km',
                'target_calories' => 'kcal',
                'non_wear_time' => 'seconds',
            ];
            foreach ($detailsFields as $field) {
                if (! array_key_exists($field, $item)) {
                    continue;
                }
                $label = Str::title(str_replace('_', ' ', $field));
                $value = $item[$field];
                [$encodedDetail, $detailMultiplier] = $this->encodeNumericValue(is_numeric($value) ? (float) $value : null);
                $event->blocks()->create([
                    'time' => $event->time,
                    'integration_id' => $integration->id,
                    'title' => $label,
                    'content' => null,
                    'value' => $encodedDetail,
                    'value_multiplier' => $detailMultiplier,
                    'value_unit' => $unitMap[$field] ?? null,
                ]);
            }
        }
    }

    protected function createWorkoutEvent(Integration $integration, array $item): void
    {
        $start = Arr::get($item, 'start_datetime');
        $end = Arr::get($item, 'end_datetime');
        $day = $start ? Str::substr($start, 0, 10) : (Arr::get($item, 'day') ?? now()->toDateString());
        $sourceId = "oura_workout_{$integration->id}_" . (Arr::get($item, 'id') ?? ($day . '_' . md5(json_encode($item))));
        $exists = Event::where('source_id', $sourceId)->where('integration_id', $integration->id)->first();
        if ($exists) {
            return;
        }

        $actor = $this->ensureUserProfile($integration);
        $target = EventObject::updateOrCreate([
            'user_id' => $integration->user_id,
            'concept' => 'workout',
            'type' => Arr::get($item, 'activity', 'workout'),
            'title' => Str::title((string) Arr::get($item, 'activity', 'Workout')),
        ], [
            'time' => $start ?? ($day . ' 00:00:00'),
            'content' => 'Oura workout session',
            'metadata' => $item,
        ]);

        $durationSec = (int) Arr::get($item, 'duration', 0);
        $calories = (float) Arr::get($item, 'calories', Arr::get($item, 'total_calories', 0));
        $event = Event::create([
            'source_id' => $sourceId,
            'time' => $start ?? ($day . ' 00:00:00'),
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'service' => 'oura',
            'domain' => self::getDomain(),
            'action' => 'did_workout',
            'value' => $durationSec,
            'value_multiplier' => 1,
            'value_unit' => 'seconds',
            'event_metadata' => [
                'end' => $end,
                'calories' => $calories,
            ],
            'target_id' => $target->id,
        ]);

        [$encodedCalories, $calMultiplier] = $this->encodeNumericValue($calories);
        $event->blocks()->create(['block_type' => 'workout',

            'time' => $event->time,
            'integration_id' => $integration->id,
            'title' => 'Calories',
            'content' => 'Estimated calories for the workout',
            'value' => $encodedCalories,
            'value_multiplier' => $calMultiplier,
            'value_unit' => 'kcal',
        ]);

        $avgHr = Arr::get($item, 'average_heart_rate');
        if ($avgHr !== null) {
            [$encodedAvgHr, $avgHrMultiplier] = $this->encodeNumericValue($avgHr);
            $event->blocks()->create(['block_type' => 'workout',

                'time' => $event->time,
                'integration_id' => $integration->id,
                'title' => 'Average Heart Rate',
                'content' => 'Average heart rate during workout',
                'value' => $encodedAvgHr,
                'value_multiplier' => $avgHrMultiplier,
                'value_unit' => 'bpm',
            ]);
        }
    }

    protected function createSessionEvent(Integration $integration, array $item): void
    {
        $start = Arr::get($item, 'start_datetime') ?? Arr::get($item, 'timestamp');
        $day = $start ? Str::substr($start, 0, 10) : (Arr::get($item, 'day') ?? now()->toDateString());
        $sourceId = "oura_session_{$integration->id}_" . (Arr::get($item, 'id') ?? ($day . '_' . md5(json_encode($item))));
        $exists = Event::where('source_id', $sourceId)->where('integration_id', $integration->id)->first();
        if ($exists) {
            return;
        }

        $actor = $this->ensureUserProfile($integration);
        $target = EventObject::updateOrCreate([
            'user_id' => $integration->user_id,
            'concept' => 'mindfulness_session',
            'type' => Arr::get($item, 'type', 'session'),
            'title' => Str::title((string) Arr::get($item, 'type', 'Session')),
        ], [
            'time' => $start ?? ($day . ' 00:00:00'),
            'content' => 'Oura guided or unguided session',
            'metadata' => $item,
        ]);

        $durationSec = (int) Arr::get($item, 'duration', 0);
        $event = Event::create([
            'source_id' => $sourceId,
            'time' => $start ?? ($day . ' 00:00:00'),
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'service' => 'oura',
            'domain' => self::getDomain(),
            'action' => 'had_mindfulness_session',
            'value' => $durationSec,
            'value_multiplier' => 1,
            'value_unit' => 'seconds',
            'target_id' => $target->id,
        ]);

        $state = Arr::get($item, 'mood', Arr::get($item, 'state'));
        if ($state) {
            $event->blocks()->create([
                'time' => $event->time,
                'integration_id' => $integration->id,
                'title' => 'State',
                'content' => (string) $state,
                'value' => null,
                'value_multiplier' => 1,
                'value_unit' => null,
            ]);
        }
    }

    protected function createTagEvent(Integration $integration, array $item): void
    {
        $timestamp = Arr::get($item, 'timestamp') ?? Arr::get($item, 'time') ?? now()->toIso8601String();
        $day = Str::substr($timestamp, 0, 10);
        $sourceId = "oura_tag_{$integration->id}_" . md5(json_encode($item));
        $exists = Event::where('source_id', $sourceId)->where('integration_id', $integration->id)->first();
        if ($exists) {
            return;
        }

        $actor = $this->ensureUserProfile($integration);
        // Create a simple target object for tag to satisfy non-null target_id
        $tagTarget = EventObject::updateOrCreate([
            'user_id' => $integration->user_id,
            'concept' => 'tag',
            'type' => 'oura_tag',
            'title' => 'Oura Tag',
        ], [
            'time' => $timestamp,
            'content' => 'Oura tag entry',
            'metadata' => $item,
        ]);

        $label = Arr::get($item, 'tag') ?? Arr::get($item, 'label', 'Tag');
        $event = Event::create([
            'source_id' => $sourceId,
            'time' => $timestamp,
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'service' => 'oura',
            'domain' => self::getDomain(),
            'action' => 'had_oura_tag',
            'value' => null,
            'value_multiplier' => 1,
            'value_unit' => null,
            'event_metadata' => [
                'day' => $day,
                'label' => $label,
            ],
            'target_id' => $tagTarget->id,
        ]);

        $event->blocks()->create(['block_type' => 'tag',

            'time' => $event->time,
            'integration_id' => $integration->id,
            'title' => 'Tag',
            'content' => (string) $label,
        ]);
    }

    /**
     * Get the appropriate log channel for this plugin
     */
    protected function getLogChannel(): string
    {
        $pluginChannel = 'api_debug_' . str_replace([' ', '-', '_'], '_', static::getIdentifier());

        return config('logging.channels.' . $pluginChannel) ? $pluginChannel : 'api_debug';
    }

    /**
     * Log webhook payload for debugging
     */
    protected function logWebhookPayload(string $service, string $integrationId, array $payload, array $headers = []): void
    {
        log_integration_webhook(
            $service,
            $integrationId,
            $this->sanitizeData($payload),
            $this->sanitizeHeaders($headers),
            true // Use per-instance logging
        );
    }

    /**
     * Sanitize headers for logging (remove sensitive data)
     */
    protected function sanitizeHeaders(array $headers): array
    {
        $sensitiveHeaders = ['authorization', 'x-api-key', 'x-auth-token'];
        $sanitized = [];

        foreach ($headers as $key => $value) {
            $lowerKey = strtolower($key);
            if (in_array($lowerKey, $sensitiveHeaders)) {
                $sanitized[$key] = '[REDACTED]';
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize data for logging (remove sensitive data)
     */
    protected function sanitizeData(array $data): array
    {
        $sensitiveKeys = ['password', 'token', 'secret', 'key', 'auth'];
        $sanitized = [];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower($key);
            if (in_array($lowerKey, $sensitiveKeys)) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeData($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize response body for logging (limit size and remove sensitive data)
     */
    protected function sanitizeResponseBody(string $body): string
    {
        // Limit response body size to prevent huge logs
        $maxLength = 10000;
        if (strlen($body) > $maxLength) {
            return substr($body, 0, $maxLength) . '... [TRUNCATED]';
        }

        // Try to parse as JSON and sanitize sensitive fields
        $parsed = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
            $sanitized = $this->sanitizeData($parsed);

            return json_encode($sanitized, JSON_PRETTY_PRINT);
        }

        return $body;
    }

    /**
     * Encode a numeric value into an integer with a multiplier to retain precision.
     * If the value has a fractional part, scale by 1000 and round.
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

    /**
     * Helper used by migration for sleep_records
     */
    private function createSleepRecordFromItem(Integration $integration, array $item): void
    {
        $start = Arr::get($item, 'bedtime_start');
        $end = Arr::get($item, 'bedtime_end');
        $day = $start ? Str::substr($start, 0, 10) : (Arr::get($item, 'day') ?? now()->toDateString());
        $id = Arr::get($item, 'id') ?? md5(json_encode([$day, Arr::get($item, 'duration', 0), Arr::get($item, 'total', 0)]));
        $sourceId = "oura_sleep_record_{$integration->id}_{$id}";
        $exists = Event::where('source_id', $sourceId)->where('integration_id', $integration->id)->first();
        if ($exists) {
            return;
        }

        $actor = $this->ensureUserProfile($integration);
        $target = EventObject::updateOrCreate([
            'user_id' => $integration->user_id,
            'concept' => 'sleep',
            'type' => 'oura_sleep_record',
            'title' => 'Sleep Record',
        ], [
            'time' => $start ?? ($day . ' 00:00:00'),
            'content' => 'Detailed sleep record including stages and efficiency',
            'metadata' => $item,
        ]);

        $duration = (int) Arr::get($item, 'duration', 0);
        $efficiency = Arr::get($item, 'efficiency');
        $event = Event::create([
            'source_id' => $sourceId,
            'time' => $start ?? ($day . ' 00:00:00'),
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'service' => 'oura',
            'domain' => self::getDomain(),
            'action' => 'slept_for',
            'value' => $duration,
            'value_multiplier' => 1,
            'value_unit' => 'seconds',
            'event_metadata' => [
                'end' => $end,
                'efficiency' => $efficiency,
            ],
            'target_id' => $target->id,
        ]);

        $stages = Arr::get($item, 'sleep_stages', []);
        $stageMap = [
            'deep' => 'Deep Sleep',
            'light' => 'Light Sleep',
            'rem' => 'REM Sleep',
            'awake' => 'Awake Time',
        ];
        foreach (['deep', 'light', 'rem', 'awake'] as $stage) {
            $seconds = Arr::get($stages, $stage);
            if ($seconds === null) {
                continue;
            }
            $event->blocks()->create(['block_type' => 'tag',

                'time' => $event->time,
                'integration_id' => $integration->id,
                'title' => $stageMap[$stage] ?? Str::title($stage) . ' Sleep',
                'content' => 'Stage duration',
                'value' => (int) $seconds,
                'value_multiplier' => 1,
                'value_unit' => 'seconds',
            ]);
        }

        $hrAvg = Arr::get($item, 'average_heart_rate');
        if ($hrAvg !== null) {
            [$encodedHrAvg, $hrAvgMultiplier] = $this->encodeNumericValue($hrAvg);
            $event->blocks()->create(['block_type' => 'sleep_stages',

                'time' => $event->time,
                'integration_id' => $integration->id,
                'title' => 'Average Heart Rate',
                'content' => 'Average sleeping heart rate',
                'value' => $encodedHrAvg,
                'value_multiplier' => $hrAvgMultiplier,
                'value_unit' => 'bpm',
            ]);
        }
    }
}
