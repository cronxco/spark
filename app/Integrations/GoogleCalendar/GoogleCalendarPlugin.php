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
                'description' => 'The Google Calendar ID to sync',
                'required' => true,
            ],
            'calendar_name' => [
                'type' => 'string',
                'label' => 'Calendar Name',
                'description' => 'Display name of the calendar',
                'required' => false,
            ],
            'update_frequency_minutes' => [
                'type' => 'integer',
                'label' => 'Update Frequency (minutes)',
                'description' => 'How often to check for calendar updates (minimum 5 minutes)',
                'required' => true,
                'min' => 5,
                'default' => 15,
            ],
            'title_include_patterns' => [
                'type' => 'array',
                'label' => 'Include Patterns',
                'description' => 'Regex patterns for event titles to include (OR logic). Leave empty to include all.',
                'required' => false,
            ],
            'title_exclude_patterns' => [
                'type' => 'array',
                'label' => 'Exclude Patterns',
                'description' => 'Regex patterns for event titles to exclude',
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
        return 'o-calendar';
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
                'icon' => 'o-calendar',
                'display_name' => 'Had Event',
                'description' => 'A timed calendar event',
                'display_with_object' => true,
                'value_unit' => 'minutes',
                'hidden' => false,
            ],
            'had_all_day_event' => [
                'icon' => 'o-calendar-days',
                'display_name' => 'Had All-Day Event',
                'description' => 'An all-day calendar event',
                'display_with_object' => true,
                'value_unit' => 'minutes',
                'hidden' => false,
            ],
        ];
    }

    public static function getBlockTypes(): array
    {
        return [
            'event_details' => [
                'icon' => 'o-information-circle',
                'display_name' => 'Event Details',
                'description' => 'Detailed information about the calendar event',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
            'event_attendees' => [
                'icon' => 'o-user-group',
                'display_name' => 'Attendees',
                'description' => 'Event attendees and responses',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
            'event_location' => [
                'icon' => 'o-map-pin',
                'display_name' => 'Location',
                'description' => 'Event location or meeting link',
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
                'icon' => 'o-calendar',
                'display_name' => 'Google Calendar',
                'description' => 'A Google Calendar',
                'hidden' => false,
            ],
            'calendar_event' => [
                'icon' => 'o-calendar-days',
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
            'access_type' => 'offline', // Request refresh token
            'prompt' => 'consent', // Force consent to get refresh token
        ];

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
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

        // Exchange code for tokens with PKCE
        $hub = SentrySdk::getCurrentHub();
        $parentSpan = $hub->getSpan();
        $span = $parentSpan?->startChild((new SpanContext)->setOp('http.client')->setDescription('POST https://oauth2.googleapis.com/token'));
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri,
            'code_verifier' => $codeVerifier,
        ]);
        $span?->finish();

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

        // Fetch primary calendar email as account_id
        $this->fetchAccountInfoForGroup($group);
    }

    public function fetchData(Integration $integration): void
    {
        // This method is not directly used - we use pullEventData instead
        // But kept for compatibility with base class expectations
    }

    public function convertData(array $externalData, Integration $integration): array
    {
        // This method is not used for OAuth plugins
        return [];
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

        try {
            $hub = SentrySdk::getCurrentHub();
            $parentSpan = $hub->getSpan();
            $span = $parentSpan?->startChild((new SpanContext)->setOp('http.client')->setDescription('GET calendar/v3/users/me/calendarList'));

            $response = Http::withToken($group->access_token)
                ->get('https://www.googleapis.com/calendar/v3/users/me/calendarList');

            $span?->finish();

            if (! $response->successful()) {
                Log::error('Failed to fetch calendar list', [
                    'group_id' => $group->id,
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);

                return [];
            }

            $data = $response->json();
            $calendars = [];

            foreach ($data['items'] ?? [] as $calendar) {
                $calendars[] = [
                    'id' => $calendar['id'],
                    'name' => $calendar['summary'] ?? 'Unnamed Calendar',
                    'description' => $calendar['description'] ?? null,
                    'primary' => $calendar['primary'] ?? false,
                    'access_role' => $calendar['accessRole'] ?? null,
                ];
            }

            return $calendars;
        } catch (Exception $e) {
            Log::error('Exception fetching calendar list', [
                'group_id' => $group->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Pull event data for fetch jobs
     */
    public function pullEventData(Integration $integration): array
    {
        $config = $integration->configuration ?? [];
        $calendarId = $config['calendar_id'] ?? null;

        if (! $calendarId) {
            Log::error('Google Calendar: No calendar_id configured', [
                'integration_id' => $integration->id,
            ]);

            return [
                'events' => [],
                'current_event_ids' => [],
                'fetched_at' => now()->toISOString(),
            ];
        }

        // Ensure token is fresh
        $group = $integration->group;
        if ($group->expiry && $group->expiry->isPast()) {
            $this->refreshToken($group);
        }

        // Fetch events from 7 days past to 30 days future
        $startDate = now()->subDays(7)->startOfDay();
        $endDate = now()->addDays(30)->endOfDay();

        try {
            $hub = SentrySdk::getCurrentHub();
            $parentSpan = $hub->getSpan();
            $span = $parentSpan?->startChild((new SpanContext)->setOp('http.client')->setDescription('GET calendar/v3/calendars/events'));

            $params = [
                'timeMin' => $startDate->toIso8601String(),
                'timeMax' => $endDate->toIso8601String(),
                'singleEvents' => true, // Expand recurring events into instances
                'orderBy' => 'startTime',
                'maxResults' => 2500,
            ];

            $response = Http::withToken($group->access_token)
                ->get("https://www.googleapis.com/calendar/v3/calendars/{$calendarId}/events", $params);

            $span?->finish();

            if (! $response->successful()) {
                Log::error('Failed to fetch calendar events', [
                    'integration_id' => $integration->id,
                    'calendar_id' => $calendarId,
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);

                return [
                    'events' => [],
                    'current_event_ids' => [],
                    'fetched_at' => now()->toISOString(),
                ];
            }

            $data = $response->json();
            $events = [];
            $currentEventIds = [];

            foreach ($data['items'] ?? [] as $event) {
                // Skip cancelled events
                if (($event['status'] ?? null) === 'cancelled') {
                    continue;
                }

                $events[] = $event;
                $currentEventIds[] = $event['id'];
            }

            Log::info('Google Calendar: Fetched events', [
                'integration_id' => $integration->id,
                'calendar_id' => $calendarId,
                'event_count' => count($events),
            ]);

            return [
                'events' => $events,
                'current_event_ids' => $currentEventIds,
                'fetched_at' => now()->toISOString(),
                'start_date' => $startDate->toISOString(),
                'end_date' => $endDate->toISOString(),
            ];
        } catch (Exception $e) {
            Log::error('Exception fetching calendar events', [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'events' => [],
                'current_event_ids' => [],
                'fetched_at' => now()->toISOString(),
            ];
        }
    }

    /**
     * Process event data
     */
    public function processEventData(Integration $integration, array $eventData): void
    {
        $config = $integration->configuration ?? [];
        $calendarId = $config['calendar_id'] ?? null;

        $events = $eventData['events'] ?? [];
        $currentEventIds = $eventData['current_event_ids'] ?? [];
        $startDate = isset($eventData['start_date']) ? Carbon::parse($eventData['start_date']) : now()->subDays(7)->startOfDay();
        $endDate = isset($eventData['end_date']) ? Carbon::parse($eventData['end_date']) : now()->addDays(30)->endOfDay();

        // Get include/exclude patterns
        $includePatterns = $config['title_include_patterns'] ?? [];
        $excludePatterns = $config['title_exclude_patterns'] ?? [];

        $processedCount = 0;
        $filteredCount = 0;

        foreach ($events as $event) {
            $eventTitle = $event['summary'] ?? 'Untitled Event';

            // Apply filtering
            if (! $this->shouldIncludeEvent($eventTitle, $includePatterns, $excludePatterns)) {
                $filteredCount++;

                continue;
            }

            $this->processEvent($integration, $event, $calendarId);
            $processedCount++;
        }

        // Handle deletions - find events in our DB that are no longer in Google's response
        $this->handleDeletedEvents($integration, $currentEventIds, $startDate, $endDate);

        Log::info('Google Calendar: Completed processing events', [
            'integration_id' => $integration->id,
            'total_events' => count($events),
            'processed_count' => $processedCount,
            'filtered_count' => $filteredCount,
        ]);
    }

    /**
     * Check if an event should be included based on filter patterns
     */
    protected function shouldIncludeEvent(string $title, array $includePatterns, array $excludePatterns): bool
    {
        // Check exclude patterns first
        foreach ($excludePatterns as $pattern) {
            if (empty($pattern)) {
                continue;
            }

            if (@preg_match($pattern, $title)) {
                return false;
            }
        }

        // If no include patterns, include everything (that wasn't excluded)
        if (empty($includePatterns)) {
            return true;
        }

        // Check include patterns (OR logic - match any)
        foreach ($includePatterns as $pattern) {
            if (empty($pattern)) {
                continue;
            }

            if (@preg_match($pattern, $title)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Process a single event
     */
    protected function processEvent(Integration $integration, array $event, string $calendarId): void
    {
        $eventId = $event['id'];
        $eventTitle = $event['summary'] ?? 'Untitled Event';

        // Determine if all-day event
        $isAllDay = isset($event['start']['date']);

        // Parse start and end times
        if ($isAllDay) {
            $startTime = Carbon::parse($event['start']['date'])->startOfDay();
            $endTime = Carbon::parse($event['end']['date'])->startOfDay();
            $durationMinutes = 1440; // 24 hours
        } else {
            $startTime = Carbon::parse($event['start']['dateTime']);
            $endTime = Carbon::parse($event['end']['dateTime']);
            $durationMinutes = $startTime->diffInMinutes($endTime);
        }

        // Create unique source ID (include start time for recurring instances)
        $sourceId = "google_calendar_{$calendarId}_{$eventId}_{$startTime->format('Y-m-d_H-i')}";

        // Check if we already processed this event
        $existingEvent = Event::where('source_id', $sourceId)
            ->where('integration_id', $integration->id)
            ->first();

        if ($existingEvent) {
            // Update if changed
            $existingEvent->update([
                'time' => $startTime,
                'value' => $durationMinutes,
                'event_metadata' => $this->buildEventMetadata($event),
            ]);

            return;
        }

        // Create or update calendar object (the calendar itself)
        $calendarObject = $this->createOrUpdateCalendar($integration);

        // Create or update event object (the specific event)
        $eventObject = $this->createOrUpdateEventObject($integration, $event);

        // Create the event record
        $eventRecord = Event::create([
            'source_id' => $sourceId,
            'time' => $startTime,
            'integration_id' => $integration->id,
            'actor_id' => $calendarObject->id,
            'service' => 'google-calendar',
            'domain' => self::getDomain(),
            'action' => $isAllDay ? 'had_all_day_event' : 'had_event',
            'value' => $durationMinutes,
            'value_multiplier' => 1,
            'value_unit' => 'minutes',
            'event_metadata' => $this->buildEventMetadata($event),
            'target_id' => $eventObject->id,
        ]);

        // Create blocks
        $this->createEventBlocks($eventRecord, $event);

        Log::info("Created event for calendar event: {$eventTitle}");
    }

    /**
     * Handle events that were deleted from Google Calendar
     */
    protected function handleDeletedEvents(Integration $integration, array $currentEventIds, Carbon $startDate, Carbon $endDate): void
    {
        // Find events in our DB within the sync window
        $existingEvents = Event::where('integration_id', $integration->id)
            ->where('service', 'google-calendar')
            ->whereBetween('time', [$startDate, $endDate])
            ->get();

        $deletedCount = 0;

        foreach ($existingEvents as $event) {
            // Extract Google event ID from source_id
            // Format: google_calendar_{calendarId}_{eventId}_{timestamp}
            $parts = explode('_', $event->source_id);
            if (count($parts) < 4) {
                continue;
            }

            $googleEventId = $parts[2]; // Third part is the event ID

            // If this event ID is not in the current list, delete it
            if (! in_array($googleEventId, $currentEventIds)) {
                $event->delete();
                $deletedCount++;
            }
        }

        if ($deletedCount > 0) {
            Log::info('Google Calendar: Deleted removed events', [
                'integration_id' => $integration->id,
                'deleted_count' => $deletedCount,
            ]);
        }
    }

    /**
     * Build event metadata
     */
    protected function buildEventMetadata(array $event): array
    {
        return [
            'google_event_id' => $event['id'],
            'description' => $event['description'] ?? null,
            'location' => $event['location'] ?? null,
            'hangout_link' => $event['hangoutLink'] ?? null,
            'html_link' => $event['htmlLink'] ?? null,
            'creator' => $event['creator'] ?? null,
            'organizer' => $event['organizer'] ?? null,
            'status' => $event['status'] ?? null,
            'visibility' => $event['visibility'] ?? null,
            'recurrence' => $event['recurrence'] ?? null,
            'recurring_event_id' => $event['recurringEventId'] ?? null,
        ];
    }

    /**
     * Create or update calendar object
     */
    protected function createOrUpdateCalendar(Integration $integration): EventObject
    {
        $config = $integration->configuration ?? [];
        $calendarName = $config['calendar_name'] ?? 'Google Calendar';

        return EventObject::firstOrCreate(
            [
                'user_id' => $integration->user_id,
                'concept' => 'calendar',
                'type' => 'google_calendar',
                'title' => $calendarName,
            ],
            [
                'time' => now(),
                'content' => "Google Calendar: {$calendarName}",
                'metadata' => [
                    'calendar_id' => $config['calendar_id'] ?? null,
                ],
            ]
        );
    }

    /**
     * Create or update event object
     */
    protected function createOrUpdateEventObject(Integration $integration, array $event): EventObject
    {
        $eventTitle = $event['summary'] ?? 'Untitled Event';

        return EventObject::updateOrCreate(
            [
                'user_id' => $integration->user_id,
                'concept' => 'event',
                'type' => 'calendar_event',
                'title' => $eventTitle,
            ],
            [
                'time' => now(),
                'content' => $event['description'] ?? $eventTitle,
                'metadata' => [
                    'google_event_id' => $event['id'],
                ],
                'url' => $event['htmlLink'] ?? null,
            ]
        );
    }

    /**
     * Create blocks for an event
     */
    protected function createEventBlocks(Event $eventRecord, array $event): void
    {
        // Event details block
        $eventRecord->createBlock([
            'block_type' => 'event_details',
            'time' => $eventRecord->time,
            'title' => 'Event Details',
            'metadata' => [
                'description' => $event['description'] ?? null,
                'status' => $event['status'] ?? null,
                'visibility' => $event['visibility'] ?? null,
                'creator' => $event['creator']['displayName'] ?? $event['creator']['email'] ?? null,
                'organizer' => $event['organizer']['displayName'] ?? $event['organizer']['email'] ?? null,
            ],
            'url' => $event['htmlLink'] ?? null,
        ]);

        // Attendees block (if there are attendees)
        if (! empty($event['attendees'])) {
            $attendeeList = [];
            foreach ($event['attendees'] as $attendee) {
                $attendeeList[] = [
                    'email' => $attendee['email'] ?? null,
                    'name' => $attendee['displayName'] ?? null,
                    'response_status' => $attendee['responseStatus'] ?? null,
                    'organizer' => $attendee['organizer'] ?? false,
                ];
            }

            $eventRecord->createBlock([
                'block_type' => 'event_attendees',
                'time' => $eventRecord->time,
                'title' => 'Attendees',
                'metadata' => [
                    'attendees' => $attendeeList,
                    'attendee_count' => count($attendeeList),
                ],
                'value' => count($attendeeList),
                'value_multiplier' => 1,
                'value_unit' => 'attendees',
            ]);
        }

        // Location block (if there's a location or meet link)
        if (! empty($event['location']) || ! empty($event['hangoutLink'])) {
            $eventRecord->createBlock([
                'block_type' => 'event_location',
                'time' => $eventRecord->time,
                'title' => 'Location',
                'metadata' => [
                    'location' => $event['location'] ?? null,
                    'hangout_link' => $event['hangoutLink'] ?? null,
                ],
                'url' => $event['hangoutLink'] ?? null,
            ]);
        }
    }

    protected function getRequiredScopes(): string
    {
        return 'https://www.googleapis.com/auth/calendar.readonly';
    }

    protected function refreshToken(IntegrationGroup $group): void
    {
        if (empty($group->refresh_token)) {
            Log::error('Google Calendar token refresh skipped: missing refresh_token', [
                'group_id' => $group->id,
            ]);

            return;
        }

        $hub = SentrySdk::getCurrentHub();
        $parentSpan = $hub->getSpan();
        $span = $parentSpan?->startChild((new SpanContext)->setOp('http.client')->setDescription('POST https://oauth2.googleapis.com/token'));
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $group->refresh_token,
            'grant_type' => 'refresh_token',
        ]);
        $span?->finish();

        if (! $response->successful()) {
            Log::error('Google Calendar token refresh failed', [
                'group_id' => $group->id,
                'response' => $response->body(),
                'status' => $response->status(),
            ]);
            throw new Exception('Failed to refresh token: ' . $response->body());
        }

        $tokenData = $response->json();

        Log::info('Google Calendar token refresh successful', [
            'group_id' => $group->id,
            'has_access_token' => isset($tokenData['access_token']),
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
        // Fetch primary calendar to get user email
        try {
            $response = Http::withToken($group->access_token)
                ->get('https://www.googleapis.com/calendar/v3/calendars/primary');

            if ($response->successful()) {
                $calendarData = $response->json();
                $group->update([
                    'account_id' => $calendarData['id'] ?? 'primary',
                ]);
            }
        } catch (Exception $e) {
            Log::warning('Failed to fetch account info for Google Calendar', [
                'group_id' => $group->id,
                'error' => $e->getMessage(),
            ]);
            // Set a default
            $group->update([
                'account_id' => 'primary',
            ]);
        }
    }
}
