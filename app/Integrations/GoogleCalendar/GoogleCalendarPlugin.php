<?php

namespace App\Integrations\GoogleCalendar;

use App\Integrations\Base\OAuthPlugin;
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

class GoogleCalendarPlugin extends OAuthPlugin
{
    protected string $baseUrl = 'https://www.googleapis.com/calendar/v3';

    protected string $authUrl = 'https://accounts.google.com/o/oauth2/v2';

    protected string $clientId;

    protected string $clientSecret;

    protected string $redirectUri;

    public function __construct()
    {
        $this->clientId = config('services.google-calendar.client_id') ?? '';
        $this->clientSecret = config('services.google-calendar.client_secret') ?? '';
        $this->redirectUri = config('services.google-calendar.redirect') ?? route('integrations.oauth.callback', ['service' => 'google-calendar']);

        // Only validate credentials in non-testing environments
        if (app()->environment() !== 'testing' && (empty($this->clientId) || empty($this->clientSecret))) {
            throw new InvalidArgumentException('Google Calendar OAuth credentials are not configured');
        }
    }

    public static function getIdentifier(): string
    {
        return 'google-calendar';
    }

    public static function getDisplayName(): string
    {
        return 'Google Calendar';
    }

    public static function getDescription(): string
    {
        return 'Sync events from your Google Calendar with filtering support.';
    }

    public static function getConfigurationSchema(): array
    {
        return [
            'calendar_id' => [
                'type' => 'string',
                'label' => 'Calendar ID',
                'description' => 'The Google Calendar ID to sync (e.g., primary or specific calendar ID)',
                'required' => true,
            ],
            'calendar_name' => [
                'type' => 'string',
                'label' => 'Calendar Name',
                'description' => 'Display name for the calendar',
                'required' => false,
            ],
            'update_frequency_minutes' => [
                'type' => 'integer',
                'label' => 'Update Frequency (minutes)',
                'description' => 'How often to check for new events (minimum 5 minutes)',
                'required' => true,
                'min' => 5,
                'default' => 15,
            ],
            'sync_days_past' => [
                'type' => 'integer',
                'label' => 'Sync Days (Past)',
                'description' => 'How many days in the past to sync events',
                'required' => false,
                'min' => 1,
                'default' => 7,
            ],
            'sync_days_future' => [
                'type' => 'integer',
                'label' => 'Sync Days (Future)',
                'description' => 'How many days in the future to sync events',
                'required' => false,
                'min' => 1,
                'default' => 30,
            ],
            'title_include_patterns' => [
                'type' => 'array',
                'label' => 'Title Include Patterns (Regex)',
                'description' => 'Only sync events whose titles match these patterns (OR logic if multiple)',
                'required' => false,
            ],
            'title_exclude_patterns' => [
                'type' => 'array',
                'label' => 'Title Exclude Patterns (Regex)',
                'description' => 'Exclude events whose titles match these patterns',
                'required' => false,
            ],
        ];
    }

    public static function getInstanceTypes(): array
    {
        return [
            'events' => [
                'label' => 'Calendar Events',
                'schema' => self::getConfigurationSchema(),
            ],
        ];
    }

    public static function getIcon(): string
    {
        return 'fab-google';
    }

    public static function getAccentColor(): string
    {
        return 'info';
    }

    public static function getDomain(): string
    {
        return 'knowledge';
    }

    public static function supportsMigration(): bool
    {
        return true;
    }

    public static function getActionTypes(): array
    {
        return [
            'had_event' => [
                'icon' => 'fas-calendar',
                'display_name' => 'Had Event',
                'description' => 'A timed calendar event',
                'display_with_object' => true,
                'value_unit' => 'minutes',
                'value_formatter' => '{{ format_duration($value * 60) }}',
                'hidden' => false,
            ],
            'had_all_day_event' => [
                'icon' => 'fas-calendar-week',
                'display_name' => 'Had All-Day Event',
                'description' => 'An all-day calendar event',
                'display_with_object' => true,
                'value_unit' => 'minutes',
                'value_formatter' => '{{ format_duration($value * 60) }}',
                'hidden' => false,
            ],
        ];
    }

    public static function getBlockTypes(): array
    {
        return [
            'event_details' => [
                'icon' => 'fas-circle-info',
                'display_name' => 'Event Details',
                'description' => 'Detailed information about the calendar event',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
            'event_attendees' => [
                'icon' => 'fas-user-group',
                'display_name' => 'Attendees',
                'description' => 'Event attendees and their responses',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
            'event_location' => [
                'icon' => 'fas-location-dot',
                'display_name' => 'Location',
                'description' => 'Event location or meeting link',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
            'event_time' => [
                'icon' => 'fas-clock',
                'display_name' => 'Event Time',
                'description' => 'Start or end time of the event',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
        ];
    }

    public static function getObjectTypes(): array
    {
        return [
            'google_calendar' => [
                'icon' => 'fas-calendar',
                'display_name' => 'Google Calendar',
                'description' => 'A Google Calendar',
                'hidden' => false,
            ],
            'calendar_event' => [
                'icon' => 'fas-calendar-check',
                'display_name' => 'Calendar Event',
                'description' => 'A calendar event',
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
        // Use only group_id in the key to avoid session_id mismatch issues
        $sessionKey = 'oauth_csrf_google_calendar_' . $group->id;
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
            'access_type' => 'offline',
            'prompt' => 'consent',
        ];

        return $this->authUrl . '/auth?' . http_build_query($params);
    }

    public function handleOAuthCallback(Request $request, IntegrationGroup $group): void
    {
        $error = $request->get('error');
        if ($error) {
            Log::error('Google Calendar OAuth callback returned error', [
                'group_id' => $group->id,
                'error' => $error,
                'error_description' => $request->get('error_description'),
            ]);
            throw new Exception('Google Calendar authorization failed: ' . $error);
        }

        $code = $request->get('code');
        if (! $code) {
            Log::error('Google Calendar OAuth callback missing authorization code', [
                'group_id' => $group->id,
            ]);
            throw new Exception('Invalid OAuth callback: missing authorization code');
        }

        $state = $request->get('state');
        if (! $state) {
            Log::error('Google Calendar OAuth callback missing state parameter', [
                'group_id' => $group->id,
            ]);
            throw new Exception('Invalid OAuth callback: missing state parameter');
        }

        // Verify state
        try {
            $stateData = decrypt($state);
        } catch (Throwable $e) {
            Log::error('Google Calendar OAuth state decryption failed', [
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
        $this->logApiRequest('POST', '/token', [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], [
            'client_id' => $this->clientId,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri,
            'code_verifier' => '[REDACTED]',
        ]);

        // Exchange code for tokens with PKCE
        $hub = SentrySdk::getCurrentHub();
        $parentSpan = $hub->getSpan();
        $tokenEndpoint = 'https://oauth2.googleapis.com/token';
        $span = $parentSpan?->startChild((new SpanContext)->setOp('http.client')->setDescription('POST ' . $tokenEndpoint));
        $response = Http::asForm()->post($tokenEndpoint, [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri,
            'code_verifier' => $codeVerifier,
        ]);
        $span?->finish();

        // Log the API response
        $this->logApiResponse('POST', '/token', $response->status(), $response->body(), $response->headers());

        if (! $response->successful()) {
            Log::error('Google Calendar token exchange failed', [
                'response' => $response->body(),
                'status' => $response->status(),
            ]);
            throw new Exception('Failed to exchange code for tokens: ' . $response->body());
        }

        $tokenData = $response->json();

        Log::info('Google Calendar token exchange successful', [
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

        // After fetching account info, check for duplicate groups
        $this->handleDuplicateGroups($group);
    }

    /**
     * Fetch available calendars for the user
     */
    public function fetchAvailableCalendars(IntegrationGroup $group): array
    {
        // Ensure token is fresh
        if ($group->expiry && $group->expiry->isPast()) {
            $this->refreshToken($group);
        }

        $hub = SentrySdk::getCurrentHub();
        $parentSpan = $hub->getSpan();
        $span = $parentSpan?->startChild((new SpanContext)->setOp('http.client')->setDescription('GET ' . $this->baseUrl . '/users/me/calendarList'));

        $response = Http::withToken($group->access_token)
            ->get($this->baseUrl . '/users/me/calendarList');
        $span?->finish();

        if (! $response->successful()) {
            Log::warning('Failed to fetch calendar list', [
                'group_id' => $group->id,
                'status' => $response->status(),
            ]);

            return [];
        }

        $calendars = [];
        foreach ($response->json()['items'] ?? [] as $calendar) {
            $calendars[] = [
                'id' => $calendar['id'],
                'name' => $calendar['summary'] ?? 'Unnamed Calendar',
                'primary' => $calendar['primary'] ?? false,
                'access_role' => $calendar['accessRole'] ?? null,
            ];
        }

        return $calendars;
    }

    /**
     * Pull event data for pull jobs
     */
    public function pullEventData(Integration $integration): array
    {
        $config = $integration->configuration ?? [];
        $calendarId = $config['calendar_id'] ?? null;

        if (! $calendarId) {
            Log::warning('Google Calendar integration missing calendar_id', [
                'integration_id' => $integration->id,
            ]);

            return [];
        }

        $group = $integration->group;
        if (! $group) {
            Log::error('Google Calendar integration missing group', [
                'integration_id' => $integration->id,
            ]);

            return [];
        }

        // Ensure token is fresh
        if ($group->expiry && $group->expiry->isPast()) {
            $this->refreshToken($group);
        }

        $syncDaysPast = (int) ($config['sync_days_past'] ?? 7);
        $syncDaysFuture = (int) ($config['sync_days_future'] ?? 30);
        $syncToken = $config['sync_token'] ?? null;

        $params = [
            'singleEvents' => 'true',
            'orderBy' => 'startTime',
            'maxResults' => 2500,
        ];

        // Use syncToken for incremental sync if available
        if ($syncToken) {
            $params['syncToken'] = $syncToken;
            Log::info('Using syncToken for incremental sync', [
                'integration_id' => $integration->id,
            ]);
        } else {
            // Full sync with time window
            $params['timeMin'] = now()->subDays($syncDaysPast)->toIso8601String();
            $params['timeMax'] = now()->addDays($syncDaysFuture)->toIso8601String();
            Log::info('Performing full sync', [
                'integration_id' => $integration->id,
                'time_min' => $params['timeMin'],
                'time_max' => $params['timeMax'],
            ]);
        }

        $this->logApiRequest('GET', "/calendars/{$calendarId}/events", [
            'Authorization' => '[REDACTED]',
        ], $params, $integration->id);

        $hub = SentrySdk::getCurrentHub();
        $parentSpan = $hub->getSpan();
        $span = $parentSpan?->startChild((new SpanContext)->setOp('http.client')->setDescription('GET ' . $this->baseUrl . "/calendars/{$calendarId}/events"));

        $response = Http::withToken($group->access_token)
            ->get($this->baseUrl . "/calendars/{$calendarId}/events", $params);
        $span?->finish();

        $this->logApiResponse('GET', "/calendars/{$calendarId}/events", $response->status(), $response->body(), $response->headers(), $integration->id);

        if (! $response->successful()) {
            // If syncToken is invalid (410 Gone), clear it and retry with full sync
            if ($response->status() === 410 && $syncToken) {
                Log::warning('SyncToken expired, performing full sync', [
                    'integration_id' => $integration->id,
                ]);

                // Clear syncToken and retry
                $config['sync_token'] = null;
                $integration->update(['configuration' => $config]);

                return $this->pullEventData($integration);
            }

            Log::warning('Failed to fetch Google Calendar events', [
                'integration_id' => $integration->id,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            return [];
        }

        $data = $response->json();

        // Store new syncToken for next incremental sync
        if (isset($data['nextSyncToken'])) {
            $config['sync_token'] = $data['nextSyncToken'];
            $integration->update(['configuration' => $config]);
            Log::info('Stored new syncToken', [
                'integration_id' => $integration->id,
            ]);
        }

        return [
            'events' => $data['items'] ?? [],
            'calendar_id' => $calendarId,
            'calendar_name' => $config['calendar_name'] ?? $calendarId,
            'sync_window' => [
                'time_min' => $params['timeMin'] ?? null,
                'time_max' => $params['timeMax'] ?? null,
            ],
        ];
    }

    /**
     * Process event data for processing jobs
     */
    public function processEventData(Integration $integration, array $rawData): void
    {
        $events = $rawData['events'] ?? [];
        $calendarId = $rawData['calendar_id'] ?? null;
        $calendarName = $rawData['calendar_name'] ?? $calendarId;
        $syncWindow = $rawData['sync_window'] ?? [];

        if (! $calendarId) {
            Log::warning('Missing calendar_id in raw data', [
                'integration_id' => $integration->id,
            ]);

            return;
        }

        // Get or create calendar EventObject
        // Create or find a calendar object scoped to the user. Use title/concept/type for consistency
        $calendarObject = EventObject::firstOrCreate(
            [
                'user_id' => $integration->user_id,
                'concept' => 'calendar',
                'type' => 'google_calendar',
                'title' => $calendarName,
            ],
            [
                'time' => now(),
                'content' => null,
                'metadata' => [
                    'source_id' => "google_calendar_{$calendarId}",
                ],
            ]
        );

        $config = $integration->configuration ?? [];
        $includePatterns = $config['title_include_patterns'] ?? [];
        $excludePatterns = $config['title_exclude_patterns'] ?? [];

        $processedEventIds = [];

        foreach ($events as $event) {
            $eventId = $event['id'] ?? null;
            $summary = $event['summary'] ?? 'Untitled Event';
            $status = $event['status'] ?? 'confirmed';

            // Skip cancelled events
            if ($status === 'cancelled') {
                continue;
            }

            if (! $eventId) {
                continue;
            }

            // Apply filtering
            if (! $this->shouldIncludeEvent($summary, $includePatterns, $excludePatterns)) {
                continue;
            }

            // Get start and end times
            $start = $event['start'] ?? [];
            $end = $event['end'] ?? [];

            // Determine if all-day event
            $isAllDay = isset($start['date']);

            if ($isAllDay) {
                $startTime = Carbon::parse($start['date'])->startOfDay();
                $endTime = Carbon::parse($end['date'])->startOfDay();
                $durationMinutes = null;
                $actionType = 'had_all_day_event';
            } else {
                $startTime = Carbon::parse($start['dateTime']);
                $endTime = Carbon::parse($end['dateTime']);
                $durationMinutes = $startTime->diffInMinutes($endTime);
                $actionType = 'had_event';
            }

            // Create unique source ID with timestamp for recurring events
            $sourceId = "google_calendar_{$calendarId}_{$eventId}_{$startTime->timestamp}";

            $processedEventIds[] = $sourceId;

            // Get or create event EventObject
            // Use a user-scoped event object for the calendar event (avoid querying objects by integration_id)
            $eventObject = EventObject::firstOrCreate(
                [
                    'user_id' => $integration->user_id,
                    'concept' => 'event',
                    'type' => 'calendar_event',
                    'title' => $summary,
                ],
                [
                    'time' => $startTime,
                    'content' => $event['description'] ?? null,
                    'metadata' => [
                        'google_event_id' => $eventId,
                        'source_id' => "google_calendar_event_{$eventId}",
                    ],
                ]
            );

            // Create or update Event
            $eventData = [
                'time' => $startTime,
                'actor_id' => $calendarObject->id,
                'target_id' => $eventObject->id,
                'service' => 'google_calendar',
                'domain' => self::getDomain(),
                'action' => $actionType,
                'value' => $durationMinutes,
                'event_metadata' => array_filter([
                    'google_event_id' => $eventId,
                    'description' => $event['description'] ?? null,
                    'location' => $event['location'] ?? null,
                    'hangout_link' => $event['hangoutLink'] ?? null,
                    'html_link' => $event['htmlLink'] ?? null,
                    'creator' => $event['creator'] ?? null,
                    'organizer' => $event['organizer'] ?? null,
                    'status' => $status,
                    'visibility' => $event['visibility'] ?? null,
                    'recurrence' => $event['recurrence'] ?? null,
                    'recurring_event_id' => $event['recurringEventId'] ?? null,
                ], fn ($value) => $value !== null),
            ];

            // Only set value_unit for timed events
            if (! $isAllDay) {
                $eventData['value_unit'] = 'minutes';
            }

            $eventRecord = Event::updateOrCreate(
                [
                    'integration_id' => $integration->id,
                    'source_id' => $sourceId,
                ],
                $eventData
            );

            // Create blocks for event details
            $this->createEventBlocks($eventRecord, $event, $isAllDay, $startTime, $endTime);
        }

        // Handle event deletion - remove events in sync window that are no longer present
        if (isset($syncWindow['time_min']) && isset($syncWindow['time_max'])) {
            $this->handleEventDeletion($integration, $processedEventIds, $syncWindow);
        }
    }

    public function fetchData(Integration $integration): void
    {
        // This method is called for direct data fetching
        // For job-based syncing, use pullEventData() and processEventData() instead
        $rawData = $this->pullEventData($integration);
        if (! empty($rawData['events'])) {
            $this->processEventData($integration, $rawData);
        }
    }

    public function convertData(array $externalData, Integration $integration): array
    {
        // This method is not used for OAuth plugins
        return [];
    }

    protected function getRequiredScopes(): string
    {
        return 'https://www.googleapis.com/auth/calendar';
    }

    protected function fetchAccountInfoForGroup(IntegrationGroup $group): void
    {
        // Ensure token is fresh
        if ($group->expiry && $group->expiry->isPast()) {
            $this->refreshToken($group);
        }

        // Get primary calendar to extract user email
        $hub = SentrySdk::getCurrentHub();
        $parentSpan = $hub->getSpan();
        $span = $parentSpan?->startChild((new SpanContext)->setOp('http.client')->setDescription('GET ' . $this->baseUrl . '/calendars/primary'));

        $response = Http::withToken($group->access_token)
            ->get($this->baseUrl . '/calendars/primary');
        $span?->finish();

        if ($response->successful()) {
            $calendarData = $response->json();
            $accountId = $calendarData['id'] ?? 'primary';

            Log::info('Google Calendar account info fetched', [
                'group_id' => $group->id,
                'account_id' => $accountId,
            ]);

            $group->update(['account_id' => $accountId]);
        } else {
            Log::warning('Failed to fetch Google Calendar account info', [
                'group_id' => $group->id,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);
        }
    }

    protected function refreshToken(IntegrationGroup $group): void
    {
        if (! $group->refresh_token) {
            Log::warning('Cannot refresh Google Calendar token: no refresh token available', [
                'group_id' => $group->id,
            ]);

            return;
        }

        Log::info('Refreshing Google Calendar access token', [
            'group_id' => $group->id,
        ]);

        // Log the API request
        $this->logApiRequest('POST', '/token', [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], [
            'client_id' => $this->clientId,
            'grant_type' => 'refresh_token',
        ]);

        $hub = SentrySdk::getCurrentHub();
        $parentSpan = $hub->getSpan();
        $tokenEndpoint = 'https://oauth2.googleapis.com/token';
        $span = $parentSpan?->startChild((new SpanContext)->setOp('http.client')->setDescription('POST ' . $tokenEndpoint));

        $response = Http::asForm()->post($tokenEndpoint, [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $group->refresh_token,
        ]);
        $span?->finish();

        // Log the API response
        $this->logApiResponse('POST', '/token', $response->status(), $response->body(), $response->headers());

        if (! $response->successful()) {
            Log::error('Failed to refresh Google Calendar token', [
                'group_id' => $group->id,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            // Check if refresh token is invalid
            $errorData = $response->json();
            if (isset($errorData['error']) && $errorData['error'] === 'invalid_grant') {
                Log::error('Google Calendar refresh token is invalid - user needs to re-authorize', [
                    'group_id' => $group->id,
                ]);
                // TODO: Notify user to re-authorize
            }

            throw new Exception('Failed to refresh access token');
        }

        $tokenData = $response->json();

        // Update tokens
        $updateData = [
            'access_token' => $tokenData['access_token'],
            'expiry' => isset($tokenData['expires_in'])
                ? now()->addSeconds($tokenData['expires_in'])
                : null,
        ];

        // Google may issue a new refresh token
        if (isset($tokenData['refresh_token'])) {
            $updateData['refresh_token'] = $tokenData['refresh_token'];
        }

        $group->update($updateData);

        Log::info('Google Calendar token refreshed successfully', [
            'group_id' => $group->id,
        ]);
    }

    /**
     * Validate CSRF token against stored session value
     * Overrides base implementation to use consistent session key format
     */
    protected function validateCsrfToken(string $token, IntegrationGroup $group): bool
    {
        // Get the session key for this group (without session_id to avoid session regeneration issues)
        $sessionKey = 'oauth_csrf_google_calendar_' . $group->id;

        // Retrieve stored token from session
        $storedToken = Session::get($sessionKey);

        if (! $storedToken) {
            Log::warning('Google Calendar CSRF token not found in session', [
                'group_id' => $group->id,
                'session_key' => $sessionKey,
            ]);

            return false; // No stored token found
        }

        // Compare tokens
        $isValid = hash_equals($storedToken, $token);

        // Remove the token from session after validation (one-time use)
        if ($isValid) {
            Session::forget($sessionKey);
            Log::info('Google Calendar CSRF token validated and removed', [
                'group_id' => $group->id,
            ]);
        } else {
            Log::warning('Google Calendar CSRF token mismatch', [
                'group_id' => $group->id,
            ]);
        }

        return $isValid;
    }

    /**
     * Check if event should be included based on filtering patterns
     */
    protected function shouldIncludeEvent(string $title, array $includePatterns, array $excludePatterns): bool
    {
        // Check exclude patterns first (any match = exclude)
        foreach ($excludePatterns as $pattern) {
            if (@preg_match($pattern, $title)) {
                return false;
            }
        }

        // If include patterns specified, must match at least one
        if (! empty($includePatterns)) {
            foreach ($includePatterns as $pattern) {
                if (@preg_match($pattern, $title)) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    /**
     * Create blocks for event details
     */
    protected function createEventBlocks(Event $event, array $eventData, bool $isAllDay, Carbon $startTime, Carbon $endTime): void
    {
        // Start and end time blocks for timed events
        if (! $isAllDay) {
            // Extract timezone from the event data
            $timezone = $eventData['start']['timeZone'] ?? $eventData['end']['timeZone'] ?? null;

            // Start time block
            $event->createBlock([
                'block_type' => 'event_time',
                'time' => $startTime,
                'title' => 'Start Time',
                'metadata' => array_filter([
                    'time' => $startTime,
                    'timezone' => $timezone,
                ], fn ($value) => $value !== null),
                'url' => $eventData['htmlLink'] ?? null,
            ]);

            // End time block
            $event->createBlock([
                'block_type' => 'event_time',
                'time' => $endTime,
                'title' => 'End Time',
                'metadata' => array_filter([
                    'time' => $endTime,
                    'timezone' => $timezone,
                ], fn ($value) => $value !== null),
                'url' => $eventData['htmlLink'] ?? null,
            ]);
        }

        // Event details block
        $detailsMetadata = array_filter([
            'description' => $eventData['description'] ?? null,
            'status' => $eventData['status'] ?? null,
            'visibility' => $eventData['visibility'] ?? null,
            'creator' => $eventData['creator'] ?? null,
            'organizer' => $eventData['organizer'] ?? null,
        ], fn ($value) => $value !== null);

        if (! empty($detailsMetadata)) {
            $event->createBlock([
                'block_type' => 'event_details',
                'time' => $event->time,
                'title' => 'Event Details',
                'metadata' => $detailsMetadata,
                'url' => $eventData['htmlLink'] ?? null,
            ]);
        }

        // Attendees block
        if (isset($eventData['attendees']) && ! empty($eventData['attendees'])) {
            $attendeeCount = count($eventData['attendees']);
            $event->createBlock([
                'block_type' => 'event_attendees',
                'time' => $event->time,
                'title' => 'Attendees',
                'metadata' => [
                    'attendees' => $eventData['attendees'],
                ],
                'value' => $attendeeCount,
                'value_unit' => 'attendees',
                'url' => $eventData['htmlLink'] ?? null,
            ]);
        }

        // Location block
        $locationMetadata = array_filter([
            'location' => $eventData['location'] ?? null,
            'conference_data' => $eventData['conferenceData'] ?? null,
        ], fn ($value) => $value !== null);

        // Determine the best URL for the location block
        $locationUrl = $eventData['hangoutLink'] ?? $eventData['htmlLink'] ?? null;

        if (! empty($locationMetadata) || $locationUrl) {
            $event->createBlock([
                'block_type' => 'event_location',
                'time' => $event->time,
                'title' => 'Location',
                'metadata' => $locationMetadata,
                'url' => $locationUrl,
            ]);
        }
    }

    /**
     * Handle event deletion - remove events that no longer exist in Google Calendar
     */
    protected function handleEventDeletion(Integration $integration, array $processedEventIds, array $syncWindow): void
    {
        $timeMin = Carbon::parse($syncWindow['time_min']);
        $timeMax = Carbon::parse($syncWindow['time_max']);

        // Get all events in the sync window that weren't in the current sync
        $eventsToDelete = Event::where('integration_id', $integration->id)
            ->where('source_id', 'like', 'google_calendar_%')
            ->whereBetween('time', [$timeMin, $timeMax])
            ->whereNotIn('source_id', $processedEventIds)
            ->get();

        if ($eventsToDelete->isNotEmpty()) {
            Log::info('Deleting events no longer in Google Calendar', [
                'integration_id' => $integration->id,
                'count' => $eventsToDelete->count(),
            ]);

            foreach ($eventsToDelete as $event) {
                $event->delete();
            }
        }
    }

    /**
     * Handle duplicate integration groups for the same Google account
     *
     * When a user completes OAuth for a Google account they've already connected,
     * we want to reuse the existing group instead of creating a duplicate.
     *
     * @param  IntegrationGroup  $group  The newly created group from OAuth
     * @return IntegrationGroup The group to use (either existing or new)
     */
    protected function handleDuplicateGroups(IntegrationGroup $group): IntegrationGroup
    {
        // Only proceed if we have an account_id
        if (! $group->account_id) {
            Log::warning('Google Calendar group has no account_id, cannot check for duplicates', [
                'group_id' => $group->id,
            ]);

            return $group;
        }

        // Check if another group exists for this user, service, and account_id
        $existingGroup = IntegrationGroup::query()
            ->where('user_id', $group->user_id)
            ->where('service', static::getIdentifier())
            ->where('account_id', $group->account_id)
            ->where('id', '!=', $group->id) // Exclude the current group
            ->first();

        if ($existingGroup) {
            Log::info('Found existing Google Calendar group for this account, merging', [
                'new_group_id' => $group->id,
                'existing_group_id' => $existingGroup->id,
                'account_id' => $group->account_id,
                'user_id' => $group->user_id,
            ]);

            // Update the existing group with the latest tokens
            // (they might have been refreshed during this OAuth flow)
            $existingGroup->update([
                'access_token' => $group->access_token,
                'refresh_token' => $group->refresh_token ?? $existingGroup->refresh_token,
                'expiry' => $group->expiry,
                'refresh_expiry' => $group->refresh_expiry,
            ]);

            // Move any integrations from the new group to the existing group
            // (this shouldn't happen in normal flow, but handle it just in case)
            Integration::where('integration_group_id', $group->id)
                ->update(['integration_group_id' => $existingGroup->id]);

            // Delete the duplicate group
            $group->delete();

            Log::info('Deleted duplicate Google Calendar group', [
                'deleted_group_id' => $group->id,
                'using_group_id' => $existingGroup->id,
            ]);

            // Store the existing group ID in the request to update the redirect
            request()->merge(['_merged_group_id' => $existingGroup->id]);

            return $existingGroup;
        }

        // No duplicate found, use the new group
        return $group;
    }
}
