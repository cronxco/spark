<?php

namespace App\Integrations\Immich;

use App\Integrations\Base\ManualPlugin;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Http;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;
use Throwable;

class ImmichPlugin extends ManualPlugin
{
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

    public static function getIdentifier(): string
    {
        return 'immich';
    }

    public static function getDisplayName(): string
    {
        return 'Immich';
    }

    public static function getDescription(): string
    {
        return 'Sync photos and people from your self-hosted Immich instance.';
    }

    public static function getGroupConfigurationSchema(): array
    {
        return [
            'server_url' => [
                'type' => 'string',
                'label' => 'Server URL',
                'required' => true,
                'description' => 'Your Immich instance URL (e.g., https://photos.example.com)',
            ],
            'api_key' => [
                'type' => 'string',
                'label' => 'API Key',
                'required' => true,
                'description' => 'API key from Immich settings',
                'secure' => true,
            ],
        ];
    }

    public static function getConfigurationSchema($instanceType = null): array
    {
        return [
            'update_frequency_minutes' => [
                'type' => 'integer',
                'label' => 'Update Frequency (minutes)',
                'required' => false,
                'min' => 15,
                'max' => 1440,
                'default' => 60,
                'description' => 'How often to sync photos (15-1440 minutes)',
            ],
            'sync_mode' => [
                'type' => 'string',
                'label' => 'Sync Mode',
                'required' => false,
                'default' => 'recent',
                'options' => ['recent', 'full'],
                'description' => 'Recent (last 30 days) or Full history',
            ],
            'include_videos' => [
                'type' => 'boolean',
                'label' => 'Include Videos',
                'required' => false,
                'default' => true,
                'description' => 'Sync video files in addition to photos',
            ],
            'include_archived' => [
                'type' => 'boolean',
                'label' => 'Include Archived',
                'required' => false,
                'default' => false,
                'description' => 'Include archived photos in sync',
            ],
            'sync_people' => [
                'type' => 'boolean',
                'label' => 'Sync People',
                'required' => false,
                'default' => true,
                'description' => 'Sync recognized people/faces from Immich',
            ],
            'cluster_radius_km' => [
                'type' => 'integer',
                'label' => 'Cluster Radius (km)',
                'required' => false,
                'min' => 1,
                'max' => 50,
                'default' => 5,
                'description' => 'Maximum distance for grouping photos (1-50 km)',
            ],
            'cluster_window_minutes' => [
                'type' => 'integer',
                'label' => 'Cluster Time Window (minutes)',
                'required' => false,
                'min' => 15,
                'max' => 360,
                'default' => 60,
                'description' => 'Maximum time window for grouping photos (15-360 min)',
            ],
        ];
    }

    public static function getInstanceTypes(): array
    {
        return [
            'photos' => [
                'label' => 'Photos',
                'schema' => self::getConfigurationSchema('photos'),
                'description' => 'Sync photos and videos from Immich',
            ],
        ];
    }

    public static function getIcon(): string
    {
        return 'fas.images';
    }

    public static function getAccentColor(): string
    {
        return 'primary';
    }

    public static function getDomain(): string
    {
        return 'media';
    }

    public static function supportsMigration(): bool
    {
        return true;
    }

    public static function getActionTypes(): array
    {
        return [
            'took_photos' => [
                'icon' => 'fas.camera',
                'display_name' => 'Took Photos',
                'description' => 'A cluster of photos was captured',
                'display_with_object' => true,
                'value_unit' => 'photos',
                'hidden' => false,
            ],
        ];
    }

    public static function getBlockTypes(): array
    {
        return [
            'immich_photo' => [
                'icon' => 'fas.image',
                'display_name' => 'Photo',
                'description' => 'Individual photo with metadata',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
            'cluster_summary' => [
                'icon' => 'fas.images',
                'display_name' => 'Cluster Summary',
                'description' => 'Overview of photo cluster',
                'display_with_object' => true,
                'value_unit' => 'photos',
                'hidden' => false,
            ],
            'cluster_people' => [
                'icon' => 'fas.users',
                'display_name' => 'People in Cluster',
                'description' => 'People appearing in this photo cluster',
                'display_with_object' => true,
                'value_unit' => 'people',
                'hidden' => false,
            ],
        ];
    }

    public static function getObjectTypes(): array
    {
        return [
            'immich_user' => [
                'icon' => 'fas.user-circle',
                'display_name' => 'Immich User',
                'description' => 'Immich account user',
                'hidden' => true,
            ],
            'immich_cluster' => [
                'icon' => 'fas.images',
                'display_name' => 'Photo Cluster',
                'description' => 'Cluster of photos taken at similar time and place',
                'hidden' => false,
            ],
            'immich_person' => [
                'icon' => 'fas.user',
                'display_name' => 'Person',
                'description' => 'Recognized person from face detection',
                'hidden' => false,
            ],
        ];
    }

    public function initializeGroup(User $user): IntegrationGroup
    {
        return IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => static::getIdentifier(),
            'account_id' => null,
            'access_token' => null,
            'refresh_token' => null,
            'expiry' => null,
            'refresh_expiry' => null,
            'auth_metadata' => [
                'server_url' => null,
                'api_key' => null,
            ],
        ]);
    }

    public function createInstance(IntegrationGroup $group, string $instanceType, array $initialConfig = [], bool $withMigration = false): Integration
    {
        // Extract server_url and api_key from config and move to auth_metadata
        $serverUrl = $initialConfig['server_url'] ?? null;
        $apiKey = $initialConfig['api_key'] ?? null;

        if ($serverUrl || $apiKey) {
            $group->auth_metadata = array_merge($group->auth_metadata ?? [], [
                'server_url' => $serverUrl ?? $group->auth_metadata['server_url'] ?? null,
                'api_key' => $apiKey ?? $group->auth_metadata['api_key'] ?? null,
            ]);
            $group->save();

            // Remove from instance config
            unset($initialConfig['server_url'], $initialConfig['api_key']);
        }

        // Pause integration if migration is requested
        if ($withMigration) {
            $initialConfig['paused'] = true;
        }

        // Derive instance name
        $defaultName = static::getDisplayName();
        if (method_exists(static::class, 'getInstanceTypes')) {
            try {
                $types = static::getInstanceTypes();
                $defaultName = $types[$instanceType]['label'] ?? ucfirst($instanceType);
            } catch (Throwable $e) {
                $defaultName = ucfirst($instanceType);
            }
        } else {
            $defaultName = ucfirst($instanceType);
        }

        return Integration::create([
            'user_id' => $group->user_id,
            'integration_group_id' => $group->id,
            'service' => static::getIdentifier(),
            'name' => $defaultName,
            'instance_type' => $instanceType,
            'configuration' => $initialConfig,
        ]);
    }

    /**
     * Pull photo data from Immich API
     */
    public function pullPhotoData(Integration $integration, string $serverUrl, string $apiKey, ?string $afterDate = null): array
    {
        $assets = [];
        $take = 100;
        $skip = 0;

        do {
            $params = [
                'take' => $take,
                'skip' => $skip,
            ];

            if ($afterDate) {
                $params['updatedAfter'] = $afterDate;
            }

            $response = $this->makeRequest(
                'GET',
                "{$serverUrl}/api/assets",
                $apiKey,
                $params,
                $integration->id
            );

            // API might return array directly or nested in 'assets' key
            $batch = is_array($response) && isset($response[0]) ? $response : ($response['assets'] ?? []);

            $assets = array_merge($assets, $batch);
            $skip += $take;

            // Continue if we got a full batch
        } while (count($batch) === $take);

        return [
            'assets' => $assets,
            'fetched_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Pull people data from Immich API
     */
    public function pullPeopleData(Integration $integration, string $serverUrl, string $apiKey): array
    {
        $response = $this->makeRequest(
            'GET',
            "{$serverUrl}/api/people",
            $apiKey,
            ['withHidden' => true],
            $integration->id
        );

        // API might return array directly or nested in 'people' key
        $people = is_array($response) && isset($response[0]) ? $response : ($response['people'] ?? []);

        return [
            'people' => $people,
            'fetched_at' => now()->toIso8601String(),
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
            true
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
            true
        );
    }

    /**
     * Make HTTP request to Immich API
     */
    protected function makeRequest(string $method, string $url, string $apiKey, array $params = [], ?string $integrationId = null): array
    {
        $hub = SentrySdk::getCurrentHub();
        $span = $hub->getSpan()?->startChild(
            (new SpanContext)->setOp('http.client')->setDescription("{$method} {$url}")
        );

        $this->logApiRequest($method, $url, ['x-api-key' => '[REDACTED]'], $params, $integrationId);

        $httpClient = Http::withHeaders(['x-api-key' => $apiKey]);

        $methodUpper = strtoupper($method);
        $response = match ($methodUpper) {
            'GET' => $httpClient->get($url, $params),
            'POST' => $httpClient->post($url, $params),
            'PUT' => $httpClient->put($url, $params),
            'PATCH' => $httpClient->patch($url, $params),
            'DELETE' => $httpClient->delete($url, $params),
            default => throw new Exception("Unsupported HTTP method: {$method}"),
        };

        $span?->finish();

        $this->logApiResponse($method, $url, $response->status(), $response->body(), $response->headers(), $integrationId);

        if (! $response->successful()) {
            throw new Exception("Immich API request failed: {$response->status()} - {$response->body()}");
        }

        return $response->json();
    }

    /**
     * Sanitize headers for logging (remove sensitive data)
     */
    protected function sanitizeHeaders(array $headers): array
    {
        $sensitiveHeaders = ['authorization', 'x-api-key', 'x-auth-token', 'cookie', 'set-cookie'];
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
        $sensitiveKeys = ['password', 'token', 'secret', 'key', 'auth', 'access_token', 'api_key'];
        $sanitized = [];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower($key);
            $isSensitive = false;
            foreach ($sensitiveKeys as $sensitiveKey) {
                if (str_contains($lowerKey, $sensitiveKey)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
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
     * Sanitize response body for logging (limit size)
     */
    protected function sanitizeResponseBody(string $body): string
    {
        $maxLength = 10000;
        if (strlen($body) > $maxLength) {
            return substr($body, 0, $maxLength) . ' ... [TRUNCATED]';
        }

        // Try to parse as JSON and sanitize sensitive fields
        $parsed = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
            $sanitized = $this->sanitizeData($parsed);

            return json_encode($sanitized, JSON_PRETTY_PRINT);
        }

        return $body;
    }
}
