<?php

namespace App\Integrations\Spotify;

use App\Integrations\Base\OAuthPlugin;
use App\Integrations\Contracts\SupportsSpotlightCommands;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;
use Throwable;

class SpotifyPlugin extends OAuthPlugin implements SupportsSpotlightCommands
{
    protected string $baseUrl = 'https://api.spotify.com/v1';

    protected string $authUrl = 'https://accounts.spotify.com';

    protected string $clientId;

    protected string $clientSecret;

    protected string $redirectUri;

    public function __construct()
    {
        $this->clientId = config('services.spotify.client_id') ?? '';
        $this->clientSecret = config('services.spotify.client_secret') ?? '';
        $this->redirectUri = config('services.spotify.redirect') ?? route('integrations.oauth.callback', ['service' => 'spotify']);

        // Only validate credentials in non-testing environments
        if (app()->environment() !== 'testing' && (empty($this->clientId) || empty($this->clientSecret))) {
            throw new InvalidArgumentException('Spotify OAuth credentials are not configured');
        }
    }

    public static function getIdentifier(): string
    {
        return 'spotify';
    }

    public static function getDisplayName(): string
    {
        return 'Spotify';
    }

    public static function getDescription(): string
    {
        return 'Sync listening history from Spotify.';
    }

    public static function getConfigurationSchema(): array
    {
        return [
            'update_frequency_minutes' => [
                'type' => 'integer',
                'label' => 'Update Frequency (minutes)',
                'description' => 'How often to check for new tracks (minimum 5 minutes, Spotify API rate limits apply)',
                'required' => true,
                'min' => 5,
                'default' => 15,
            ],
            'auto_tag_genres' => [
                'type' => 'boolean',
                'label' => 'Auto-tag by Genre',
                'description' => 'Automatically tag events with track genres',
            ],
            'auto_tag_artists' => [
                'type' => 'boolean',
                'label' => 'Auto-tag by Artist',
                'description' => 'Automatically tag events with artist names',
            ],
            'include_album_art' => [
                'type' => 'boolean',
                'label' => 'Include Album Art',
                'description' => 'Create blocks with album artwork',
            ],
            'track_podcasts' => [
                'type' => 'boolean',
                'label' => 'Track Podcast Listening',
                'description' => 'Create events when you listen to podcast episodes',
                'default' => true,
            ],
            'podcast_min_listen_minutes' => [
                'type' => 'integer',
                'label' => 'Minimum Listen Minutes',
                'description' => 'Minimum minutes listened before creating event',
                'min' => 1,
                'max' => 60,
                'default' => 5,
            ],
            'podcast_session_timeout_hours' => [
                'type' => 'integer',
                'label' => 'Session Timeout (hours)',
                'description' => 'Hours of inactivity before starting a new listening session',
                'min' => 1,
                'max' => 24,
                'default' => 4,
            ],
        ];
    }

    public static function getInstanceTypes(): array
    {
        return [
            'listening' => [
                'label' => 'Listening Activity',
                'schema' => self::getConfigurationSchema(),
            ],
        ];
    }

    public static function getIcon(): string
    {
        return 'o-musical-note';
    }

    public static function getAccentColor(): string
    {
        return 'success';
    }

    public static function getDomain(): string
    {
        return 'media';
    }

    public static function supportsMigration(): bool
    {
        return true;
    }

    /**
     * Provide Spotlight commands for Spotify integration.
     */
    public static function getSpotlightCommands(): array
    {
        return [
            'spotify-sync-recent' => [
                'title' => 'Sync Recent Spotify Plays',
                'subtitle' => 'Fetch your latest listening history from Spotify',
                'icon' => 'musical-note',
                'action' => 'dispatch_event',
                'actionParams' => [
                    'name' => 'trigger-spotify-sync',
                    'data' => ['type' => 'recent'],
                    'close' => true,
                ],
                'priority' => 7,
            ],
            'spotify-view-stats' => [
                'title' => 'View Spotify Listening Stats',
                'subtitle' => 'See your music trends and top artists',
                'icon' => 'chart-bar',
                'action' => 'jump_to',
                'actionParams' => [
                    'path' => '/integrations?filter=spotify',
                ],
                'priority' => 5,
            ],
        ];
    }

    public static function getActionTypes(): array
    {
        return [
            'listened_to' => [
                'icon' => 'o-play',
                'display_name' => 'Listened to Track',
                'description' => 'A track that was listened to on Spotify',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
        ];
    }

    public static function getBlockTypes(): array
    {
        return [
            'album_art' => [
                'icon' => 'o-photo',
                'display_name' => 'Album Artwork',
                'description' => 'Album cover artwork for the track',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
            'track_details' => [
                'icon' => 'o-information-circle',
                'display_name' => 'Track Details',
                'description' => 'Detailed information about the track',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
            'artist' => [
                'icon' => 'o-user',
                'display_name' => 'Artist',
                'description' => 'Musical artist who created the track',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
            'track_info' => [
                'icon' => 'o-information-circle',
                'display_name' => 'Track Information',
                'description' => 'Detailed track metadata and information',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
            'episode_art' => [
                'icon' => 'o-photo',
                'display_name' => 'Episode Artwork',
                'description' => 'Cover art for the podcast episode',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
            'episode_details' => [
                'icon' => 'o-information-circle',
                'display_name' => 'Episode Details',
                'description' => 'Detailed information about the podcast episode',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
        ];
    }

    public static function getObjectTypes(): array
    {
        return [
            'spotify_user' => [
                'icon' => 'o-user',
                'display_name' => 'Spotify User',
                'description' => 'A Spotify user account',
                'hidden' => false,
            ],
            'spotify_track' => [
                'icon' => 'o-musical-note',
                'display_name' => 'Spotify Track',
                'description' => 'A Spotify track',
                'hidden' => false,
            ],
            'spotify_podcast_episode' => [
                'icon' => 'o-microphone',
                'display_name' => 'Podcast Episode',
                'description' => 'A podcast episode on Spotify',
                'hidden' => false,
            ],
        ];
    }

    public function getOAuthUrl(IntegrationGroup $group): string
    {
        // Generate PKCE code verifier and challenge
        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->generateCodeChallenge($codeVerifier);

        // Generate CSRF token
        $csrfToken = Str::random(32);

        // Store CSRF token in session for validation
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

        // Spotify uses accounts.spotify.com for authorization
        return $this->authUrl . '/authorize?' . http_build_query($params);
    }

    public function handleOAuthCallback(Request $request, IntegrationGroup $group): void
    {
        $error = $request->get('error');
        if ($error) {
            Log::error('Spotify OAuth callback returned error', [
                'group_id' => $group->id,
                'error' => $error,
                'error_description' => $request->get('error_description'),
            ]);
            throw new Exception('Spotify authorization failed: ' . $error);
        }

        $code = $request->get('code');
        if (! $code) {
            Log::error('Spotify OAuth callback missing authorization code', [
                'group_id' => $group->id,
            ]);
            throw new Exception('Invalid OAuth callback: missing authorization code');
        }

        $state = $request->get('state');
        if (! $state) {
            Log::error('Spotify OAuth callback missing state parameter', [
                'group_id' => $group->id,
            ]);
            throw new Exception('Invalid OAuth callback: missing state parameter');
        }

        // Verify state
        try {
            $stateData = decrypt($state);
        } catch (Throwable $e) {
            Log::error('Spotify OAuth state decryption failed', [
                'group_id' => $group->id,
                'exception' => $e->getMessage(),
            ]);
            throw new Exception('Invalid OAuth callback: state decryption failed');
        }

        if ((string) ($stateData['group_id'] ?? '') !== (string) $group->id) {
            throw new Exception('Invalid state parameter');
        }

        // Validate CSRF token
        if (! isset($stateData['csrf_token']) || ! $this->validateCsrfToken($stateData['csrf_token'], $group)) {
            throw new Exception('Invalid CSRF token');
        }

        // Get code verifier from state
        $codeVerifier = $stateData['code_verifier'] ?? null;
        if (! $codeVerifier) {
            throw new Exception('Missing code verifier');
        }

        // Log the API request
        $this->logApiRequest('POST', '/api/token', [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], [
            'client_id' => $this->clientId,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri,
            'code_verifier' => '[REDACTED]', // PKCE code verifier
        ]);

        // Exchange code for tokens with PKCE - Spotify uses accounts.spotify.com for token exchange
        $hub = SentrySdk::getCurrentHub();
        $parentSpan = $hub->getSpan();
        $span = $parentSpan?->startChild((new SpanContext)->setOp('http.client')->setDescription('POST https://accounts.spotify.com/api/token'));
        $response = Http::asForm()->post('https://accounts.spotify.com/api/token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri,
            'code_verifier' => $codeVerifier,
        ]);
        $span?->finish();

        // Log the API response
        $this->logApiResponse('POST', '/api/token', $response->status(), $response->body(), $response->headers());

        if (! $response->successful()) {
            Log::error('Spotify token exchange failed', [
                'response' => $response->body(),
                'status' => $response->status(),
            ]);
            throw new Exception('Failed to exchange code for tokens: ' . $response->body());
        }

        $tokenData = $response->json();

        Log::info('Spotify token exchange successful', [
            'group_id' => $group->id,
            'has_access_token' => isset($tokenData['access_token']),
            'has_refresh_token' => isset($tokenData['refresh_token']),
            'expires_in' => $tokenData['expires_in'] ?? null,
        ]);

        // Update group with tokens
        $group->update([
            'access_token' => $tokenData['access_token'],
            'refresh_token' => $tokenData['refresh_token'] ?? null,
            'expiry' => isset($tokenData['expires_in'])
                ? now()->addSeconds($tokenData['expires_in'])
                : null,
        ]);

        // Fetch account information
        $this->fetchAccountInfoForGroup($group);
    }

    public function fetchData(Integration $integration): void
    {
        $accountId = $integration->group?->account_id ?? $integration->account_id;
        Log::info("Fetching Spotify data for user {$accountId}");

        // Get recently played tracks (last 50)
        $recentlyPlayed = $this->getRecentlyPlayed($integration);

        foreach ($recentlyPlayed as $playedItem) {
            $this->processTrackPlay($integration, $playedItem, 'recently_played');
        }
    }

    public function convertData(array $externalData, Integration $integration): array
    {
        // This method is not used for OAuth plugins
        return [];
    }

    // Public helper for migration: process a single recently played item
    public function processRecentlyPlayedMigrationItem(Integration $integration, array $playedItem): void
    {
        $this->processTrackPlay($integration, $playedItem, 'recently_played');
    }

    // Public helper for migration: ensure token is fresh; refresh if expired
    public function ensureFreshToken(IntegrationGroup $group): void
    {
        if ($group->expiry && $group->expiry->isPast()) {
            $this->refreshToken($group);
        }
    }

    /**
     * Make an authenticated request to the Spotify API
     */
    public function makeAuthenticatedApiRequest(string $endpoint, Integration $integration, array $query = []): array
    {
        return $this->makeAuthenticatedRequest($endpoint, $integration, $query);
    }

    /**
     * Pull listening data for pull jobs
     */
    public function pullListeningData(Integration $integration): array
    {
        $accountId = $integration->group?->account_id ?? $integration->account_id;

        Log::info("Fetching Spotify listening data for user {$accountId}", [
            'integration_id' => $integration->id,
        ]);

        $listeningData = [
            'account_id' => $accountId,
            'recently_played' => [],
            'fetched_at' => now()->toISOString(),
        ];

        // Skip fetching currently playing to avoid duplicates

        try {
            $config = $integration->configuration ?? [];
            $afterMs = (int) ($config['spotify_after_ms'] ?? 0);

            // Get recently played tracks
            $recentlyPlayed = $this->getRecentlyPlayed($integration);

            // Advance 'after' cursor to the newest played_at we saw
            $maxPlayedMs = 0;
            foreach ($recentlyPlayed as $it) {
                if (isset($it['played_at'])) {
                    $ms = (int) round(Carbon::parse($it['played_at'])->valueOf());
                    if ($ms > $maxPlayedMs) {
                        $maxPlayedMs = $ms;
                    }
                }
            }
            if ($maxPlayedMs > $afterMs) {
                $config['spotify_after_ms'] = $maxPlayedMs;
            }

            $integration->update(['configuration' => $config]);

            $listeningData['recently_played'] = $recentlyPlayed;

            Log::info('Spotify: Fetched recently played tracks', [
                'integration_id' => $integration->id,
                'track_count' => count($recentlyPlayed),
                'used_after_ms' => $afterMs,
                'new_after_ms' => $config['spotify_after_ms'] ?? null,
            ]);
        } catch (Exception $e) {
            Log::warning('Spotify: Failed to get recently played tracks', [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
            ]);
            // Continue without recently played data
        }

        // Fetch currently playing for podcast episodes
        $trackPodcasts = $config['track_podcasts'] ?? true;

        if ($trackPodcasts) {
            try {
                $currentlyPlaying = $this->getCurrentlyPlaying($integration);

                if ($currentlyPlaying && ($currentlyPlaying['item']['type'] ?? null) === 'episode') {
                    $listeningData['currently_playing_episode'] = $currentlyPlaying;

                    Log::info('Spotify: Detected podcast episode playing', [
                        'integration_id' => $integration->id,
                        'episode_name' => $currentlyPlaying['item']['name'] ?? 'Unknown',
                        'show_name' => $currentlyPlaying['item']['show']['name'] ?? 'Unknown',
                        'progress_ms' => $currentlyPlaying['progress_ms'] ?? 0,
                    ]);
                }
            } catch (Exception $e) {
                Log::warning('Spotify: Failed to get currently playing for podcasts', [
                    'integration_id' => $integration->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $listeningData;
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
     * Encode a numeric value into an integer with a multiplier to retain precision.
     * If the value has a fractional part, scale by 1000 and round.
     * Returns [encodedInt|null, multiplier|null].
     */
    public function encodeNumericValue(null|int|float|string $raw, int $defaultMultiplier = 1): array
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
     * Create events safely with race condition protection
     */
    public function createEventsSafely(Integration $integration, array $eventData): void
    {
        foreach ($eventData as $data) {
            // Use updateOrCreate to prevent race conditions
            $event = Event::updateOrCreate(
                [
                    'integration_id' => $integration->id,
                    'source_id' => $data['source_id'],
                ],
                [
                    'time' => $data['time'],
                    'actor_id' => $this->createOrUpdateObject($integration, $data['actor'])->id,
                    'service' => 'spotify',
                    'domain' => $data['domain'],
                    'action' => $data['action'],
                    'value' => $data['value'] ?? null,
                    'value_multiplier' => $data['value_multiplier'] ?? 1,
                    'value_unit' => $data['value_unit'] ?? null,
                    'event_metadata' => $data['event_metadata'] ?? [],
                    'target_id' => $this->createOrUpdateObject($integration, $data['target'])->id,
                ]
            );

            // Create blocks if any
            if (isset($data['blocks'])) {
                foreach ($data['blocks'] as $blockData) {
                    $event->createBlock([
                        'time' => $blockData['time'] ?? $event->time,
                        'block_type' => $blockData['block_type'] ?? '',
                        'title' => $blockData['title'],
                        'metadata' => $blockData['metadata'] ?? [],
                        'url' => $blockData['url'] ?? null,
                        'media_url' => $blockData['media_url'] ?? null,
                        'value' => $blockData['value'] ?? null,
                        'value_multiplier' => $blockData['value_multiplier'] ?? 1,
                        'value_unit' => $blockData['value_unit'] ?? null,
                        'embeddings' => $blockData['embeddings'] ?? null,
                    ]);
                }
            }

            Log::info('Spotify: Created event safely', [
                'integration_id' => $integration->id,
                'source_id' => $data['source_id'],
                'action' => $data['action'],
            ]);
        }
    }

    /**
     * Helper method to create or update objects.
     */
    public function createOrUpdateObject(Integration $integration, array $objectData): EventObject
    {
        return EventObject::updateOrCreate(
            [
                'user_id' => $integration->user_id,
                'concept' => $objectData['concept'],
                'type' => $objectData['type'],
                'title' => $objectData['title'],
            ],
            [
                'time' => $objectData['time'] ?? now(),
                'content' => $objectData['content'] ?? null,
                'metadata' => $objectData['metadata'] ?? [],
                'url' => $objectData['url'] ?? null,
                'media_url' => $objectData['image_url'] ?? null,
                'embeddings' => $objectData['embeddings'] ?? null,
            ]
        );
    }

    public function processListeningData(Integration $integration, array $listeningData): void
    {
        // Check for potential duplicate processing
        $this->checkForDuplicateProcessing($integration, $listeningData);

        // Process recently played tracks
        if (! empty($listeningData['recently_played'])) {
            $processedCount = 0;
            $skippedCount = 0;

            foreach ($listeningData['recently_played'] as $playedItem) {
                try {
                    $this->processTrackPlay($integration, $playedItem, 'recently_played');
                    $processedCount++;
                } catch (Exception $e) {
                    Log::error('Spotify: Failed to process recently played track', [
                        'integration_id' => $integration->id,
                        'track_id' => $playedItem['track']['id'] ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info('Spotify: Completed processing recently played tracks', [
                'integration_id' => $integration->id,
                'total_tracks' => count($listeningData['recently_played']),
                'processed_count' => $processedCount,
            ]);
        }

        // Process podcast episode if currently playing
        if (! empty($listeningData['currently_playing_episode'])) {
            try {
                $this->processEpisodeListen($integration, $listeningData['currently_playing_episode']);
            } catch (Exception $e) {
                Log::error('Spotify: Failed to process podcast episode', [
                    'integration_id' => $integration->id,
                    'episode_id' => $listeningData['currently_playing_episode']['item']['id'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function checkForDuplicateProcessing(Integration $integration, array $listeningData): void
    {
        $recentlyPlayed = $listeningData['recently_played'] ?? [];
        $duplicateCount = 0;

        foreach ($recentlyPlayed as $playedItem) {
            if (! isset($playedItem['track']['id'])) {
                continue;
            }

            $trackId = $playedItem['track']['id'];
            $playedAt = $playedItem['played_at'];

            // Check if this exact track play was processed very recently (within last 5 minutes)
            $recentEvent = Event::where('integration_id', $integration->id)
                ->where('service', 'spotify')
                ->where('action', 'listened_to')
                ->where('event_metadata->track_id', $trackId)
                ->where('time', '>=', now()->subMinutes(5))
                ->first();

            if ($recentEvent) {
                $duplicateCount++;
                Log::warning('Spotify: Potential duplicate processing detected', [
                    'integration_id' => $integration->id,
                    'track_id' => $trackId,
                    'track_name' => $playedItem['track']['name'] ?? 'Unknown',
                    'played_at' => $playedAt,
                    'recent_event_time' => $recentEvent->time->toISOString(),
                    'time_difference_minutes' => now()->diffInMinutes($recentEvent->time),
                ]);
            }
        }

        if ($duplicateCount > 0) {
            Log::warning('Spotify: Multiple potential duplicate tracks detected in this batch', [
                'integration_id' => $integration->id,
                'total_tracks' => count($recentlyPlayed),
                'potential_duplicates' => $duplicateCount,
                'percentage' => round(($duplicateCount / count($recentlyPlayed)) * 100, 1) . '%',
            ]);
        }
    }

    protected function getRequiredScopes(): string
    {
        return 'user-read-currently-playing user-read-recently-played user-read-email user-read-private';
    }

    protected function refreshToken(IntegrationGroup $group): void
    {
        if (empty($group->refresh_token)) {
            Log::error('Spotify token refresh skipped: missing refresh_token', [
                'group_id' => $group->id,
            ]);

            return;
        }
        // Log the API request
        $this->logApiRequest('POST', '/api/token', [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], [
            'client_id' => $this->clientId,
            'grant_type' => 'refresh_token',
        ]);

        $hub = SentrySdk::getCurrentHub();
        $parentSpan = $hub->getSpan();
        $span = $parentSpan?->startChild((new SpanContext)->setOp('http.client')->setDescription('POST https://accounts.spotify.com/api/token'));
        $response = Http::asForm()->post('https://accounts.spotify.com/api/token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $group->refresh_token,
            'grant_type' => 'refresh_token',
        ]);
        $span?->finish();

        // Log the API response
        $this->logApiResponse('POST', '/api/token', $response->status(), $response->body(), $response->headers());

        if (! $response->successful()) {
            Log::error('Spotify token refresh failed', [
                'group_id' => $group->id,
                'response' => $response->body(),
                'status' => $response->status(),
            ]);
            throw new Exception('Failed to refresh token: ' . $response->body());
        }

        $tokenData = $response->json();

        Log::info('Spotify token refresh successful', [
            'group_id' => $group->id,
            'has_access_token' => isset($tokenData['access_token']),
            'has_refresh_token' => isset($tokenData['refresh_token']),
            'expires_in' => $tokenData['expires_in'] ?? null,
        ]);

        $group->update([
            'access_token' => $tokenData['access_token'],
            'refresh_token' => $tokenData['refresh_token'] ?? $group->refresh_token,
            'expiry' => isset($tokenData['expires_in'])
                ? now()->addSeconds($tokenData['expires_in'])
                : null,
        ]);
    }

    protected function fetchAccountInfoForGroup(IntegrationGroup $group): void
    {
        // Create a temp Integration bound to the group to reuse HTTP helper
        $temp = new Integration;
        $temp->setRelation('group', $group);
        $userData = $this->makeAuthenticatedRequest('/me', $temp);

        $group->update([
            'account_id' => $userData['id'],
        ]);
    }

    protected function getCurrentlyPlaying(Integration $integration, array $queryParams = []): ?array
    {
        try {
            $hub = SentrySdk::getCurrentHub();
            $parentSpan = $hub->getSpan();
            $span = $parentSpan?->startChild((new SpanContext)->setOp('http.client')->setDescription('GET ' . $this->baseUrl . '/me/player/currently-playing'));
            // Use group token if available (new architecture)
            $group = $integration->group;
            $token = $integration->access_token; // legacy fallback
            if ($group) {
                if ($group->expiry && $group->expiry->isPast()) {
                    $this->refreshToken($group);
                }
                $token = $group->access_token;
            }

            // Default to including episodes for podcast support
            $params = array_merge(['additional_types' => 'episode'], $queryParams);

            // Log the API request
            $this->logApiRequest('GET', '/me/player/currently-playing', [
                'Authorization' => '[REDACTED]',
            ], $params, $integration->id);

            $response = Http::withToken($token)
                ->get($this->baseUrl . '/me/player/currently-playing', $params);
            $span?->finish();

            // Log the API response
            $this->logApiResponse('GET', '/me/player/currently-playing', $response->status(), $response->body(), $response->headers(), $integration->id);

            if ($response->status() === 204) {
                // No track currently playing
                return null;
            }

            if (! $response->successful()) {
                Log::warning('Failed to get currently playing track', [
                    'integration_id' => $integration->id,
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);

                return null;
            }

            return $response->json();
        } catch (Exception $e) {
            Log::error('Exception getting currently playing track', [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function getRecentlyPlayed(Integration $integration): array
    {
        try {
            $hub = SentrySdk::getCurrentHub();
            $parentSpan = $hub->getSpan();
            $span = $parentSpan?->startChild((new SpanContext)->setOp('http.client')->setDescription('GET ' . $this->baseUrl . '/me/player/recently-played'));
            // Use group token if available (new architecture)
            $group = $integration->group;
            $token = $integration->access_token; // legacy fallback
            if ($group) {
                if ($group->expiry && $group->expiry->isPast()) {
                    $this->refreshToken($group);
                }
                $token = $group->access_token;
            }
            // Log the API request
            $this->logApiRequest('GET', '/me/player/recently-played', [
                'Authorization' => '[REDACTED]',
            ], [
                'limit' => 50,
            ], $integration->id);

            $response = Http::withToken($token)
                ->get($this->baseUrl . '/me/player/recently-played', [
                    'limit' => 50,
                ]);
            $span?->finish();

            // Log the API response
            $this->logApiResponse('GET', '/me/player/recently-played', $response->status(), $response->body(), $response->headers(), $integration->id);

            if (! $response->successful()) {
                Log::warning('Failed to get recently played tracks', [
                    'integration_id' => $integration->id,
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);

                return [];
            }

            $data = $response->json();

            return $data['items'] ?? [];
        } catch (Exception $e) {
            Log::error('Exception getting recently played tracks', [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    protected function processTrackPlay(Integration $integration, array $playData, string $source): void
    {
        $track = $playData['track'] ?? $playData['item'] ?? null;
        if (! $track) {
            return;
        }

        $playedAt = $playData['played_at'] ?? $playData['timestamp'] ?? now();
        $progressMs = $playData['progress_ms'] ?? 0;

        // Create unique source ID for this play
        $sourceId = "spotify_{$track['id']}_{$playedAt}";

        // Check if we already processed this play
        $existingEvent = Event::where('source_id', $sourceId)
            ->where('integration_id', $integration->id)
            ->first();

        if ($existingEvent) {
            return; // Already processed
        }

        // Create or update user (actor)
        $user = $this->createOrUpdateUser($integration);

        // Create or update track (target)
        $trackObject = $this->createOrUpdateTrack($integration, $track);

        // Create the event
        $event = Event::create([
            'source_id' => $sourceId,
            'time' => $playedAt,
            'integration_id' => $integration->id,
            'actor_id' => $user->id,
            'actor_metadata' => [
                'spotify_user_id' => $integration->group?->account_id ?? $integration->account_id,
            ],
            'service' => 'spotify',
            'domain' => self::getDomain(),
            'action' => 'listened_to',
            'event_metadata' => [
                'source' => $source,
                'progress_ms' => $progressMs,
                'is_playing' => $source === 'currently_playing',
                'context_type' => $playData['context']['type'] ?? null,
                'track_id' => $track['id'],
                'album_id' => $track['album']['id'] ?? null,
                'artist_ids' => collect($track['artists'])->pluck('id')->toArray(),
            ],
            'target_id' => $trackObject->id,
            'target_metadata' => [
                'spotify_track_id' => $track['id'],
                'spotify_album_id' => $track['album']['id'] ?? null,
                'spotify_artist_ids' => collect($track['artists'])->pluck('id')->toArray(),
            ],
        ]);

        // Create blocks for rich content
        $this->createTrackBlocks($event, $track, $integration);

        // Auto-tag the event
        $this->autoTagEvent($event, $track, $integration, $playData);

        Log::info("Created event for track: {$track['name']} by " . collect($track['artists'])->pluck('name')->implode(', '));
    }

    protected function createOrUpdateUser(Integration $integration): EventObject
    {
        $metadata = [
            'spotify_user_id' => $integration->group?->account_id ?? $integration->account_id,
            'email' => $integration->configuration['email'] ?? null,
            'country' => $integration->configuration['country'] ?? null,
            'product' => $integration->configuration['product'] ?? null,
        ];

        $url = $integration->group?->account_id
            ? "https://open.spotify.com/user/{$integration->group->account_id}"
            : ($integration->account_id ? "https://open.spotify.com/user/{$integration->account_id}" : null);

        // Use firstOrCreate to avoid updating 'time' on every call
        $user = EventObject::firstOrCreate(
            [
                'user_id' => $integration->user_id,
                'concept' => 'user',
                'type' => 'spotify_user',
                'title' => $integration->name,
            ],
            [
                'time' => now(),
                'content' => 'Spotify user account',
                'media_url' => null,
            ]
        );

        // Update metadata and URL (these can change)
        $user->update([
            'metadata' => $metadata,
            'url' => $url,
        ]);

        return $user;
    }

    protected function createOrUpdateTrack(Integration $integration, array $track): EventObject
    {
        $artists = collect($track['artists'])->pluck('name')->implode(', ');
        $album = $track['album']['name'] ?? 'Unknown Album';

        return EventObject::updateOrCreate(
            [
                'user_id' => $integration->user_id,
                'concept' => 'track',
                'type' => 'spotify_track',
                'title' => $track['name'],
            ],
            [
                'time' => now(),
                'content' => "Track: {$track['name']}\nArtist: {$artists}\nAlbum: {$album}",
                'metadata' => [
                    'spotify_track_id' => $track['id'],
                    'spotify_album_id' => $track['album']['id'] ?? null,
                    'spotify_artist_ids' => collect($track['artists'])->pluck('id')->toArray(),
                    'duration_ms' => $track['duration_ms'] ?? 0,
                    'explicit' => $track['explicit'] ?? false,
                    'popularity' => $track['popularity'] ?? 0,
                    'track_number' => $track['track_number'] ?? null,
                    'disc_number' => $track['disc_number'] ?? 1,
                ],
                'url' => $track['external_urls']['spotify'] ?? null,
                'media_url' => $track['album']['images'][0]['url'] ?? null,
            ]
        );
    }

    protected function createTrackBlocks(Event $event, array $track, Integration $integration): void
    {
        $configuration = $integration->configuration ?? [];

        // Album art block (check if enabled in configuration)
        $includeAlbumArt = $configuration['include_album_art'] ?? ['enabled'];
        if (in_array('enabled', $includeAlbumArt) && ! empty($track['album']['images'])) {
            $albumImage = $track['album']['images'][0];
            $event->createBlock([
                'block_type' => 'album_art',
                'time' => $event->time,
                'title' => 'Album Art',
                'metadata' => [
                    'text' => "Album artwork for {$track['album']['name']}",
                ],
                'url' => $track['external_urls']['spotify'] ?? null,
                'media_url' => $albumImage['url'],
                'value' => $albumImage['width'] ?? 300,
                'value_multiplier' => 1,
                'value_unit' => 'pixels',
            ]);
        }

        // Track details block
        $artists = collect($track['artists'])->pluck('name')->implode(', ');
        $duration = gmdate('i:s', ($track['duration_ms'] ?? 0) / 1000);

        $event->createBlock([
            'block_type' => 'track_details',
            'time' => $event->time,
            'title' => 'Track Details',
            'metadata' => [
                'track' => $track['name'],
                'artists' => $artists,
                'album' => $track['album']['name'] ?? null,
                'duration' => $duration,
                'popularity' => $track['popularity'] ?? null,
            ],
            'url' => $track['external_urls']['spotify'] ?? null,
            'media_url' => null,
            'value' => $track['popularity'] ?? 0,
            'value_multiplier' => 1,
            'value_unit' => 'popularity',
        ]);

        // Artist information block
        if (! empty($track['artists'])) {
            $artist = $track['artists'][0];
            $event->createBlock([
                'block_type' => 'artist',
                'time' => $event->time,
                'title' => 'Artist Info',
                'metadata' => [
                    'artist' => $artist['name'] ?? null,
                    'spotify_id' => $artist['id'] ?? null,
                ],
                'url' => $artist['external_urls']['spotify'] ?? null,
                'media_url' => null,
                'value' => null,
                'value_multiplier' => 1,
                'value_unit' => null,
            ]);
        }
    }

    protected function autoTagEvent(Event $event, array $track, Integration $integration, array $playData = []): void
    {
        // Simplified, typed tags only
        // Artists
        foreach ($track['artists'] ?? [] as $artist) {
            if (! empty($artist['name'])) {
                $event->attachTag($artist['name'], 'music_artist');
            }
        }

        // Album
        if (! empty($track['album']['name'])) {
            $event->attachTag($track['album']['name'], 'music_album');
        }

        // Listening context (e.g. album, playlist)
        $contextType = $playData['context']['type'] ?? null;
        if (! empty($contextType)) {
            $event->attachTag($contextType, 'spotify_context');
        }
    }

    /**
     * Process a currently playing podcast episode
     */
    protected function processEpisodeListen(Integration $integration, array $episodeData): void
    {
        $episode = $episodeData['item'] ?? null;
        if (! $episode || ($episode['type'] ?? null) !== 'episode') {
            return;
        }

        $episodeId = $episode['id'];
        $progressMs = $episodeData['progress_ms'] ?? 0;
        $durationMs = $episode['duration_ms'] ?? 0;

        if ($durationMs <= 0) {
            Log::warning('Spotify: Episode has invalid duration', [
                'integration_id' => $integration->id,
                'episode_id' => $episodeId,
            ]);

            return;
        }

        $config = $integration->configuration ?? [];
        $sessionTimeoutHours = $config['podcast_session_timeout_hours'] ?? 4;

        // Look for existing event for this episode within session timeout
        $existingEvent = Event::where('integration_id', $integration->id)
            ->where('service', 'spotify')
            ->where('action', 'listened_to')
            ->where('event_metadata->type', 'episode')
            ->where('event_metadata->episode_id', $episodeId)
            ->where('time', '>=', now()->subHours($sessionTimeoutHours))
            ->first();

        if ($existingEvent) {
            $this->updateEpisodeEvent($existingEvent, $progressMs, $durationMs);

            return;
        }

        // Check if we should create event
        $minMinutes = $config['podcast_min_listen_minutes'] ?? 5;
        $listenMinutes = $progressMs / 60000;

        if ($listenMinutes >= $minMinutes) {
            $this->createPodcastEvent($integration, $episode, $progressMs);
        } else {
            Log::debug('Spotify: Episode below threshold, not creating event yet', [
                'integration_id' => $integration->id,
                'episode_id' => $episodeId,
                'episode_name' => $episode['name'] ?? 'Unknown',
                'listen_minutes' => round($listenMinutes, 1),
                'min_minutes' => $minMinutes,
            ]);
        }
    }

    /**
     * Update an existing podcast episode event with new progress
     */
    protected function updateEpisodeEvent(Event $event, int $progressMs, int $durationMs): void
    {
        $metadata = $event->event_metadata ?? [];
        $currentMaxProgress = $metadata['max_progress_ms'] ?? 0;
        $newMaxProgress = max($currentMaxProgress, $progressMs);

        // Only update if progress increased
        if ($newMaxProgress > $currentMaxProgress) {
            $metadata['max_progress_ms'] = $newMaxProgress;
            $metadata['progress_ms'] = $progressMs;

            $event->update([
                'value' => round($newMaxProgress / 60000), // Minutes listened
                'event_metadata' => $metadata,
            ]);

            Log::debug('Spotify: Updated podcast episode progress', [
                'event_id' => $event->id,
                'episode_id' => $metadata['episode_id'] ?? 'unknown',
                'progress_minutes' => round($newMaxProgress / 60000),
            ]);
        }
    }

    /**
     * Create a new event for a podcast episode
     */
    protected function createPodcastEvent(Integration $integration, array $episode, int $progressMs): void
    {
        $user = $this->createOrUpdateUser($integration);
        $episodeObject = $this->createOrUpdateEpisode($integration, $episode);

        $sourceId = sprintf(
            'spotify_podcast_%s_%s',
            $episode['id'],
            now()->format('Y-m-d')
        );

        // Check for existing event (defensive against race conditions)
        if (Event::where('source_id', $sourceId)->where('integration_id', $integration->id)->exists()) {
            Log::debug('Spotify: Podcast event already exists', [
                'integration_id' => $integration->id,
                'source_id' => $sourceId,
            ]);

            return;
        }

        $durationMs = $episode['duration_ms'];
        $listenMinutes = round($progressMs / 60000);

        $event = Event::create([
            'source_id' => $sourceId,
            'time' => now(),
            'integration_id' => $integration->id,
            'actor_id' => $user->id,
            'actor_metadata' => [
                'spotify_user_id' => $integration->group?->account_id ?? $integration->account_id,
            ],
            'service' => 'spotify',
            'domain' => self::getDomain(),
            'action' => 'listened_to',
            'value' => $listenMinutes,
            'value_multiplier' => 1,
            'value_unit' => 'minutes',
            'event_metadata' => [
                'type' => 'episode',
                'episode_id' => $episode['id'],
                'show_id' => $episode['show']['id'],
                'show_name' => $episode['show']['name'],
                'duration_ms' => $durationMs,
                'progress_ms' => $progressMs,
                'max_progress_ms' => $progressMs,
            ],
            'target_id' => $episodeObject->id,
            'target_metadata' => [
                'spotify_episode_id' => $episode['id'],
                'spotify_show_id' => $episode['show']['id'],
            ],
        ]);

        // Create blocks for rich content
        $this->createEpisodeBlocks($event, $episode, $integration);

        // Auto-tag the event
        $this->autoTagPodcastEvent($event, $episode);

        Log::info("Spotify: Created podcast event for: {$episode['name']}", [
            'event_id' => $event->id,
            'show' => $episode['show']['name'] ?? 'Unknown',
            'listen_minutes' => $listenMinutes,
        ]);
    }

    /**
     * Create or update an EventObject for a podcast episode
     */
    protected function createOrUpdateEpisode(Integration $integration, array $episode): EventObject
    {
        $showName = $episode['show']['name'] ?? 'Unknown Podcast';
        $durationFormatted = gmdate('H:i:s', ($episode['duration_ms'] ?? 0) / 1000);

        return EventObject::updateOrCreate(
            [
                'user_id' => $integration->user_id,
                'concept' => 'episode',
                'type' => 'spotify_podcast_episode',
                'title' => $episode['name'],
            ],
            [
                'time' => now(),
                'content' => "Episode: {$episode['name']}\nPodcast: {$showName}\nDuration: {$durationFormatted}",
                'metadata' => [
                    'spotify_episode_id' => $episode['id'],
                    'spotify_show_id' => $episode['show']['id'],
                    'show_name' => $showName,
                    'publisher' => $episode['show']['publisher'] ?? null,
                    'duration_ms' => $episode['duration_ms'] ?? 0,
                    'release_date' => $episode['release_date'] ?? null,
                ],
                'url' => $episode['external_urls']['spotify'] ?? null,
                'media_url' => $episode['images'][0]['url'] ?? $episode['show']['images'][0]['url'] ?? null,
            ]
        );
    }

    /**
     * Create blocks for a podcast episode event
     */
    protected function createEpisodeBlocks(Event $event, array $episode, Integration $integration): void
    {
        $configuration = $integration->configuration ?? [];

        // Episode art block
        $images = $episode['images'] ?? $episode['show']['images'] ?? [];
        $includeArt = $configuration['include_album_art'] ?? ['enabled'];

        if (in_array('enabled', $includeArt) && ! empty($images)) {
            $image = $images[0];
            $event->createBlock([
                'block_type' => 'episode_art',
                'time' => $event->time,
                'title' => 'Episode Artwork',
                'metadata' => [
                    'text' => "Artwork for {$episode['name']}",
                ],
                'url' => $episode['external_urls']['spotify'] ?? null,
                'media_url' => $image['url'],
                'value' => $image['width'] ?? 300,
                'value_multiplier' => 1,
                'value_unit' => 'pixels',
            ]);
        }

        // Episode details block
        $durationFormatted = gmdate('H:i:s', ($episode['duration_ms'] ?? 0) / 1000);

        $event->createBlock([
            'block_type' => 'episode_details',
            'time' => $event->time,
            'title' => 'Episode Details',
            'metadata' => [
                'episode' => $episode['name'],
                'show' => $episode['show']['name'] ?? null,
                'publisher' => $episode['show']['publisher'] ?? null,
                'duration' => $durationFormatted,
                'release_date' => $episode['release_date'] ?? null,
            ],
            'url' => $episode['external_urls']['spotify'] ?? null,
            'media_url' => null,
            'value' => $episode['duration_ms'] ?? 0,
            'value_multiplier' => 60000,
            'value_unit' => 'minutes',
        ]);
    }

    /**
     * Auto-tag a podcast episode event
     */
    protected function autoTagPodcastEvent(Event $event, array $episode): void
    {
        // Podcast/show name
        if (! empty($episode['show']['name'])) {
            $event->attachTag($episode['show']['name'], 'podcast_show');
        }

        // Publisher
        if (! empty($episode['show']['publisher'])) {
            $event->attachTag($episode['show']['publisher'], 'podcast_publisher');
        }

        // Context type
        $event->attachTag('podcast', 'spotify_context');
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
}
