<?php

namespace App\Integrations\Spotify;

use App\Integrations\Base\OAuthPlugin;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
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

class SpotifyPlugin extends OAuthPlugin
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
        return 'Connect your Spotify account to track your listening activity and create events for each track you play';
    }

    public static function getConfigurationSchema(): array
    {
        return [
            'update_frequency_minutes' => [
                'type' => 'integer',
                'label' => 'Update Frequency (minutes)',
                'description' => 'How often to check for new tracks (minimum 1 minute, Spotify API rate limits apply)',
                'required' => true,
                'min' => 1,
                'default' => 1,
            ],
            'auto_tag_genres' => [
                'type' => 'array',
                'label' => 'Auto-tag by Genre',
                'description' => 'Automatically tag events with track genres',
                'options' => [
                    'enabled' => 'Enable genre tagging',
                ],
            ],
            'auto_tag_artists' => [
                'type' => 'array',
                'label' => 'Auto-tag by Artist',
                'description' => 'Automatically tag events with artist names',
                'options' => [
                    'enabled' => 'Enable artist tagging',
                ],
            ],
            'include_album_art' => [
                'type' => 'array',
                'label' => 'Include Album Art',
                'description' => 'Create blocks with album artwork',
                'options' => [
                    'enabled' => 'Include album artwork',
                ],
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
                    $event->blocks()->create([
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

    protected function getCurrentlyPlaying(Integration $integration): ?array
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
            // Log the API request
            $this->logApiRequest('GET', '/me/player/currently-playing', [
                'Authorization' => '[REDACTED]',
            ], [], $integration->id);

            $response = Http::withToken($token)
                ->get($this->baseUrl . '/me/player/currently-playing');
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
        return EventObject::updateOrCreate(
            [
                'user_id' => $integration->user_id,
                'concept' => 'user',
                'type' => 'spotify_user',
                'title' => $integration->name,
            ],
            [
                'time' => now(),
                'content' => 'Spotify user account',
                'metadata' => [
                    'spotify_user_id' => $integration->group?->account_id ?? $integration->account_id,
                    'email' => $integration->configuration['email'] ?? null,
                    'country' => $integration->configuration['country'] ?? null,
                    'product' => $integration->configuration['product'] ?? null,
                ],
                'url' => $integration->group?->account_id
                    ? "https://open.spotify.com/user/{$integration->group->account_id}"
                    : ($integration->account_id ? "https://open.spotify.com/user/{$integration->account_id}" : null),
                'media_url' => null,
            ]
        );
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
            $event->blocks()->create([
                'block_type' => 'album_art',
                'time' => $event->time,
                'integration_id' => $integration->id,
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

        $event->blocks()->create([
            'block_type' => 'track_details',
            'time' => $event->time,
            'integration_id' => $integration->id,
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
            $event->blocks()->create([
                'block_type' => 'artist',
                'time' => $event->time,
                'integration_id' => $integration->id,
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
