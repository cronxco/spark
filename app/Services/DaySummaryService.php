<?php

namespace App\Services;

use App\Integrations\PluginRegistry;
use App\Models\Event;
use App\Models\Integration;
use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use App\Models\User;
use App\Support\MoneyDirection;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class DaySummaryService
{
    private ?MetricPresentation $presentation = null;

    private bool $summaryDateIsToday = false;

    /**
     * Generate a compact summary for a single date.
     *
     * @param  array<string>|null  $domains
     */
    public function generateSummary(User $user, Carbon $date, ?array $domains = null): array
    {
        // Every day-scoped endpoint resolves and names the day's timezone
        // the same way — the user's effective (acknowledged
        // time-travel) timezone, not just the profile default — and that
        // same value decides which calendar day's events this summary
        // covers.
        $timezone = app(EffectiveTimezoneResolver::class)->timezoneFor($user);
        $localDate = Carbon::parse($date->toDateString(), $timezone)->startOfDay();
        $this->summaryDateIsToday = $localDate->isToday();
        $startOfDay = $localDate->copy()->utc();
        $endOfDay = $localDate->copy()->endOfDay()->utc();

        // Query all events for this date
        $events = $this->queryEvents($user, $startOfDay, $endOfDay, $domains);

        // Pre-load metrics for baseline comparisons
        $metricsCache = $this->loadMetricsForEvents($user, $events);

        // Build domain sections
        $sections = [];

        if (! $domains || in_array('health', $domains)) {
            $sections['health'] = $this->buildHealthSection($events, $metricsCache);
        }

        if (! $domains || in_array('activity', $domains)) {
            $sections['activity'] = $this->buildActivitySection($events, $metricsCache);
        }

        if (! $domains || in_array('money', $domains)) {
            $sections['money'] = $this->buildMoneySection($events, $user);
        }

        if (! $domains || in_array('media', $domains)) {
            $sections['media'] = $this->buildMediaSection($events);
        }

        if (! $domains || in_array('knowledge', $domains)) {
            $sections['knowledge'] = $this->buildKnowledgeSection($events);
        }

        // Build sync status
        $syncStatus = $this->buildSyncStatus($user, $events, $localDate);

        // Build anomalies
        $anomalies = $this->buildAnomalies($user, $localDate);

        return [
            'date' => $localDate->toDateString(),
            'timezone' => $timezone,
            // `effective_timezone` is the name the rest of the mobile API
            // (Flint digests, check-ins) already uses; `timezone` is kept
            // for existing clients. Both are the same resolved value.
            'effective_timezone' => $timezone,
            'sync_status' => $syncStatus,
            'sections' => $sections,
            'anomalies' => $anomalies,
        ];
    }

    /**
     * Query events for a date range, filtered by user and optionally domains.
     */
    protected function queryEvents(
        User $user,
        Carbon $startDate,
        Carbon $endDate,
        ?array $domains = null
    ): Collection {
        $query = Event::query()
            ->withoutInternal()
            ->whereHas('integration', fn ($q) => $q->where('user_id', $user->id))
            ->whereBetween('time', [$startDate, $endDate])
            ->with(['actor', 'target', 'blocks', 'tags']);

        if (! empty($domains) && is_array($domains)) {
            $query->whereIn('domain', $domains);
        }

        $events = $query->orderBy('time', 'desc')
            ->limit(500)
            ->get();

        // Filter out excluded actions
        return $events->reject(function ($event) {
            return $this->shouldExcludeAction($event->service, $event->action);
        });
    }

    /**
     * Pre-load metric statistics for baseline comparisons.
     *
     * @return array<string, array{statistic: MetricStatistic, trends: Collection}>
     */
    protected function loadMetricsForEvents(User $user, Collection $events): array
    {
        $metricsCache = [];

        $metricKeys = $events
            ->filter(fn ($e) => $e->value !== null && $e->value_unit !== null)
            ->map(fn ($e) => [
                'service' => $e->service,
                'action' => $e->action,
                'unit' => $e->value_unit,
            ])
            ->unique(fn ($m) => "{$m['service']}.{$m['action']}.{$m['unit']}")
            ->values();

        foreach ($metricKeys as $key) {
            $cacheKey = "{$key['service']}.{$key['action']}.{$key['unit']}";

            $statistic = MetricStatistic::where('user_id', $user->id)
                ->where('service', $key['service'])
                ->where('action', $key['action'])
                ->where('value_unit', $key['unit'])
                ->first();

            if (! $statistic || ! $statistic->hasValidStatistics()) {
                continue;
            }

            $recentTrends = MetricTrend::where('metric_statistic_id', $statistic->id)
                ->where('detected_at', '>=', now()->subDays(7))
                ->unacknowledged()
                ->get();

            $metricsCache[$cacheKey] = [
                'statistic' => $statistic,
                'trends' => $recentTrends,
            ];
        }

        return $metricsCache;
    }

    /**
     * Build the health section (Oura sleep, readiness, biometrics).
     */
    protected function buildHealthSection(Collection $events, array $metricsCache): array
    {
        $healthEvents = $events->filter(fn ($e) => $e->service === 'oura');
        $section = [];

        // Sleep score
        $sleepScore = $healthEvents->firstWhere('action', 'had_sleep_score');
        if ($sleepScore) {
            $entry = ['event_id' => $sleepScore->id, 'score' => $sleepScore->formatted_value];
            $this->attachBaseline($entry, $sleepScore, $metricsCache);

            // Get contributors from blocks
            $contributors = $sleepScore->blocks->where('block_type', 'contributor');
            if ($contributors->isNotEmpty()) {
                $entry['contributors'] = $contributors->mapWithKeys(fn ($b) => [
                    $b->title => $b->formatted_value,
                ])->all();
            }

            $section['sleep_score'] = $entry;
        }

        // Sleep duration
        $sleepDuration = $healthEvents->firstWhere('action', 'slept_for');
        if ($sleepDuration) {
            $entry = ['event_id' => $sleepDuration->id, 'duration_seconds' => $sleepDuration->formatted_value];
            $this->attachBaseline($entry, $sleepDuration, $metricsCache);

            // Sleep stages from blocks
            $stages = $sleepDuration->blocks->where('block_type', 'sleep_stage');
            if ($stages->isNotEmpty()) {
                $entry['stages'] = $stages->mapWithKeys(fn ($b) => [
                    $b->title => $b->formatted_value,
                ])->all();
            }

            $section['sleep_duration'] = $entry;
        }

        // Readiness score
        $readiness = $healthEvents->firstWhere('action', 'had_readiness_score');
        if ($readiness) {
            $entry = ['event_id' => $readiness->id, 'score' => $readiness->formatted_value];
            $this->attachBaseline($entry, $readiness, $metricsCache);

            $contributors = $readiness->blocks->where('block_type', 'contributor');
            if ($contributors->isNotEmpty()) {
                $entry['contributors'] = $contributors->mapWithKeys(fn ($b) => [
                    $b->title => $b->formatted_value,
                ])->all();
            }

            $section['readiness_score'] = $entry;
        }

        // Activity score
        $activity = $healthEvents->firstWhere('action', 'had_activity_score');
        if ($activity) {
            $entry = ['event_id' => $activity->id, 'score' => $activity->formatted_value];
            $this->attachBaseline($entry, $activity, $metricsCache);
            $section['activity_score'] = $entry;
        }

        // Heart rate
        $heartRate = $healthEvents->firstWhere('action', 'had_heart_rate');
        if ($heartRate) {
            $entry = ['event_id' => $heartRate->id, 'value' => $heartRate->formatted_value, 'unit' => 'bpm'];
            $this->attachBaseline($entry, $heartRate, $metricsCache);
            $section['heart_rate'] = $entry;
        }

        // SpO2
        $spo2 = $healthEvents->firstWhere('action', 'had_spo2');
        if ($spo2) {
            $entry = ['event_id' => $spo2->id, 'value' => $spo2->formatted_value, 'unit' => '%'];
            $this->attachBaseline($entry, $spo2, $metricsCache);
            $section['spo2'] = $entry;
        }

        // Stress
        $stress = $healthEvents->firstWhere('action', 'had_stress_score');
        if ($stress) {
            $entry = ['event_id' => $stress->id, 'value' => $stress->formatted_value, 'unit' => 'stress_level'];
            $this->attachBaseline($entry, $stress, $metricsCache);
            $section['stress'] = $entry;
        }

        // Resilience
        $resilience = $healthEvents->firstWhere('action', 'had_resilience_score');
        if ($resilience) {
            $entry = ['event_id' => $resilience->id, 'value' => $resilience->formatted_value, 'unit' => 'resilience_level'];
            $this->attachBaseline($entry, $resilience, $metricsCache);
            $section['resilience'] = $entry;
        }

        // HRV (Apple Health)
        $hrv = $events->first(fn ($e) => $e->service === 'apple_health' && $e->action === 'had_heart_rate_variability');
        if ($hrv) {
            $entry = ['event_id' => $hrv->id, 'value' => $hrv->formatted_value, 'unit' => 'ms'];
            $this->attachBaseline($entry, $hrv, $metricsCache);
            $section['hrv'] = $entry;
        }

        // Cardiovascular age
        $cvAge = $healthEvents->firstWhere('action', 'had_cardiovascular_age');
        if ($cvAge) {
            $section['cardiovascular_age'] = ['event_id' => $cvAge->id, 'value' => $cvAge->formatted_value, 'unit' => 'years'];
        }

        // VO2 Max (check Oura first, then Apple Health)
        $vo2 = $healthEvents->firstWhere('action', 'had_vo2_max')
            ?? $events->first(fn ($e) => $e->service === 'apple_health' && $e->action === 'had_vo2_max');
        if ($vo2) {
            $entry = ['event_id' => $vo2->id, 'value' => $vo2->formatted_value, 'unit' => $vo2->value_unit];
            $this->attachBaseline($entry, $vo2, $metricsCache);
            $section['vo2_max'] = $entry;
        }

        return $section;
    }

    /**
     * Build the activity section (steps, distance, workouts).
     */
    protected function buildActivitySection(Collection $events, array $metricsCache): array
    {
        $ahEvents = $events->filter(fn ($e) => $e->service === 'apple_health');
        $section = [];

        // Simple metric mappings
        $simpleMetrics = [
            'steps' => ['action' => 'had_step_count', 'unit' => 'steps'],
            'distance_km' => ['action' => 'had_walking_running_distance', 'unit' => 'km'],
            'active_energy_kcal' => ['action' => 'had_active_energy', 'unit' => 'kcal'],
            'exercise_minutes' => ['action' => 'had_apple_exercise_time', 'unit' => 'min'],
            'flights_climbed' => ['action' => 'had_flights_climbed', 'unit' => 'flights'],
            'stand_hours' => ['action' => 'had_apple_stand_hour', 'unit' => 'hours'],
            'resting_heart_rate' => ['action' => 'had_resting_heart_rate', 'unit' => 'bpm'],
        ];

        foreach ($simpleMetrics as $key => $config) {
            $event = $ahEvents->firstWhere('action', $config['action']);
            if ($event) {
                $entry = ['event_id' => $event->id, 'value' => $event->formatted_value, 'unit' => $config['unit']];
                $this->attachBaseline($entry, $event, $metricsCache);
                $section[$key] = $entry;
            }
        }

        // Workouts (from both Oura and Apple Health)
        $workouts = $events->filter(fn ($e) => $e->action === 'did_workout');
        if ($workouts->isNotEmpty()) {
            $section['workouts'] = $workouts->map(function ($event) {
                $workout = [
                    'event_id' => $event->id,
                    'source' => $event->service,
                    'type' => $event->target?->title ?? 'Unknown',
                    'calories' => $event->formatted_value,
                    'time' => $event->time->toISOString(),
                ];

                // Duration from blocks
                $duration = $event->blocks->firstWhere('block_type', 'duration');
                if ($duration) {
                    $workout['duration_seconds'] = $duration->formatted_value;
                }

                // Distance from blocks
                $distance = $event->blocks->firstWhere('block_type', 'distance');
                if ($distance) {
                    $workout['distance'] = $distance->formatted_value;
                    $workout['distance_unit'] = $distance->value_unit;
                }

                return $workout;
            })->values()->all();
        }

        // Hevy strength workouts
        $hevyWorkouts = $events->filter(fn ($e) => $e->service === 'hevy' && $e->action === 'completed_workout');
        if ($hevyWorkouts->isNotEmpty()) {
            $section['strength_workouts'] = $hevyWorkouts->map(function ($event) {
                $workout = [
                    'event_id' => $event->id,
                    'title' => $event->target?->title ?? 'Workout',
                    'total_volume_kg' => $event->formatted_value,
                    'time' => $event->time->toISOString(),
                ];

                $exercises = $event->blocks->where('block_type', 'exercise');
                if ($exercises->isNotEmpty()) {
                    $workout['exercises'] = $exercises->map(fn ($b) => [
                        'name' => $b->title,
                        'details' => $b->getContent(),
                    ])->values()->all();
                }

                return $workout;
            })->values()->all();
        }

        return $section;
    }

    /**
     * Build the money section (transactions, receipts).
     */
    protected function buildMoneySection(Collection $events, User $user): array
    {
        $section = [];
        $transactionActions = ['payment_to', 'payment_from', 'made_transaction', 'card_payment_to',
            'bank_transfer_to', 'bank_transfer_from', 'direct_debit_to', 'card_refund_from',
            'monzo_me_from', 'other_credit_from', 'pot_transfer_to', 'pot_withdrawal_to'];

        $transactions = $events->filter(fn ($e) => in_array($e->action, $transactionActions));

        if ($transactions->isNotEmpty()) {
            $section['transactions'] = $transactions->map(function ($event) {
                $tx = [
                    'event_id' => $event->id,
                    'merchant' => $event->target?->title ?? 'Unknown',
                    'amount' => $event->formatted_value,
                    'currency' => $event->value_unit ?? 'GBP',
                    'action' => $event->action,
                    'service' => $event->service,
                    'time' => $event->time->toISOString(),
                    // The client no longer infers in/out/internal from the
                    // action-name suffix — an open vocabulary that grows with
                    // every integration.
                    'direction' => MoneyDirection::for($event),
                ];

                if ($event->actor?->title) {
                    $tx['account'] = $event->actor->title;
                }

                if ($event->event_metadata) {
                    if (! empty($event->event_metadata['notes'])) {
                        $tx['reference'] = $event->event_metadata['notes'];
                    }
                    if (isset($event->event_metadata['card_last4'])) {
                        $tx['card_last4'] = $event->event_metadata['card_last4'];
                    }
                    if (isset($event->event_metadata['pending'])) {
                        $tx['pending'] = $event->event_metadata['pending'];
                    }
                }

                return $tx;
            })->values()->all();

            // Split spend from internal transfers. Moving money between the
            // user's own accounts/pots is not spending and must not be
            // counted as such — see MoneyDirection.
            $totalSpend = $transactions
                ->filter(fn ($e) => MoneyDirection::for($e) === MoneyDirection::OUT)
                ->sum(fn ($e) => abs($e->formatted_value));
            $internalTransfers = $transactions
                ->filter(fn ($e) => MoneyDirection::for($e) === MoneyDirection::INTERNAL)
                ->sum(fn ($e) => abs($e->formatted_value));
            $totalIn = $transactions
                ->filter(fn ($e) => MoneyDirection::for($e) === MoneyDirection::IN)
                ->sum(fn ($e) => abs($e->formatted_value));

            $section['total_spend'] = round($totalSpend, 2);
            $section['internal_transfers'] = round($internalTransfers, 2);
            $section['total_in'] = round($totalIn, 2);

            // A day-level baseline for the one day-total figure that didn't
            // have one — vs_baseline_pct on `total_spend`, computed
            // over the user's own daily spend history, or an explicit reason
            // there is none yet.
            $baseline = $this->dailySpendBaseline($user);
            if ($baseline !== null) {
                $section['total_spend_vs_baseline_pct'] = $baseline['mean'] != 0.0
                    ? round((($totalSpend - $baseline['mean']) / abs($baseline['mean'])) * 100, 1)
                    : 0.0;
            } else {
                $section['total_spend_baseline_unavailable_reason'] = 'insufficient_history';
            }
        }

        // Receipts
        $receipts = $events->filter(fn ($e) => $e->service === 'receipt' && $e->action === 'had_receipt_from');
        if ($receipts->isNotEmpty()) {
            $section['receipts'] = $receipts->map(function ($event) {
                $receipt = [
                    'event_id' => $event->id,
                    'merchant' => $event->target?->title ?? 'Unknown',
                    'amount' => $event->formatted_value,
                    'currency' => $event->value_unit ?? 'GBP',
                    'time' => $event->time->toISOString(),
                ];

                $lineItems = $event->blocks->where('block_type', 'receipt_line_item');
                if ($lineItems->isNotEmpty()) {
                    $receipt['line_items'] = $lineItems->map(fn ($b) => [
                        'item' => $b->title,
                        'amount' => $b->formatted_value,
                    ])->values()->all();
                }

                return $receipt;
            })->values()->all();
        }

        return $section;
    }

    /**
     * Build the media section (Spotify sessions, Untappd checkins).
     */
    protected function buildMediaSection(Collection $events): array
    {
        $section = [];

        // Spotify session clustering
        $spotifyEvents = $events
            ->filter(fn ($e) => $e->service === 'spotify' && $e->action === 'listened_to')
            ->sortBy('time')
            ->values();

        if ($spotifyEvents->isNotEmpty()) {
            $sessions = [];
            $currentSession = [$spotifyEvents->first()];

            for ($i = 1; $i < $spotifyEvents->count(); $i++) {
                $gap = $spotifyEvents[$i]->time->diffInMinutes($spotifyEvents[$i - 1]->time);

                if ($gap > 30) {
                    $sessions[] = $currentSession;
                    $currentSession = [];
                }

                $currentSession[] = $spotifyEvents[$i];
            }
            $sessions[] = $currentSession;

            $section['listening_sessions'] = collect($sessions)->map(function ($sessionEvents) {
                $sessionEvents = collect($sessionEvents);
                $first = $sessionEvents->first();
                $last = $sessionEvents->last();

                // Count artists
                $artists = $sessionEvents->map(function ($e) {
                    $artistBlock = $e->blocks->firstWhere('block_type', 'artist');

                    return $artistBlock?->title ?? $e->target?->content ?? 'Unknown';
                })->countBy();

                $topArtist = $artists->sortDesc()->keys()->first();

                // Check if >50% same album
                $albums = $sessionEvents->map(fn ($e) => $e->target?->title)->filter()->countBy();
                $topAlbum = $albums->sortDesc()->first();
                $isAlbumSession = $topAlbum && ($topAlbum / $sessionEvents->count()) > 0.5;

                return [
                    'first_event_id' => $first->id,
                    'last_event_id' => $last->id,
                    'start' => $first->time->toISOString(),
                    'end' => $last->time->toISOString(),
                    'track_count' => $sessionEvents->count(),
                    'top_artist' => $topArtist,
                    'description' => $isAlbumSession
                        ? $albums->sortDesc()->keys()->first()
                        : 'Mixed tracks',
                ];
            })->values()->all();
        }

        // Untappd checkins
        $untappdEvents = $events->filter(fn ($e) => $e->service === 'untappd' && $e->action === 'drank');
        if ($untappdEvents->isNotEmpty()) {
            $section['beer_checkins'] = $untappdEvents->map(function ($event) {
                $checkin = [
                    'event_id' => $event->id,
                    'beer' => $event->target?->title ?? 'Unknown',
                    'rating' => $event->formatted_value,
                    'time' => $event->time->toISOString(),
                ];

                // Brewery from blocks
                $brewery = $event->blocks->first(fn ($b) => in_array($b->block_type, ['beer_brewery', 'brewery_details']));
                if ($brewery) {
                    $checkin['brewery'] = $brewery->title ?? $brewery->getContent();
                }

                return $checkin;
            })->values()->all();
        }

        // Goodreads
        $readingEvents = $events->filter(fn ($e) => $e->service === 'goodreads');
        if ($readingEvents->isNotEmpty()) {
            $section['reading'] = $readingEvents->map(function ($event) {
                return [
                    'event_id' => $event->id,
                    'action' => $event->action,
                    'book' => $event->target?->title ?? 'Unknown',
                    'rating' => $event->action === 'finished_reading' ? $event->formatted_value : null,
                ];
            })->values()->all();
        }

        return $section;
    }

    /**
     * Build the knowledge section (Outline notes, bookmarks, newsletters).
     */
    protected function buildKnowledgeSection(Collection $events): array
    {
        $section = [];

        // Outline notes
        $outlineEvents = $events->filter(fn ($e) => $e->service === 'outline');
        if ($outlineEvents->isNotEmpty()) {
            $section['outline_note_exists'] = true;
            $section['outline_notes'] = $outlineEvents->map(fn ($e) => [
                'event_id' => $e->id,
                'action' => $e->action,
                'title' => $e->target?->title ?? $e->actor?->title ?? 'Note',
            ])->values()->all();
        }

        // Bookmarks (Fetch, Reddit, Karakeep)
        $bookmarks = $events->filter(fn ($e) => $e->action === 'bookmarked');
        if ($bookmarks->isNotEmpty()) {
            $section['bookmarks'] = $bookmarks->map(function ($event) {
                $bookmark = [
                    'event_id' => $event->id,
                    'title' => $event->displayTargetTitle() ?? 'Untitled',
                    'source' => $event->service,
                    'url' => $event->displayTargetUrl(),
                ];

                // Summary from blocks
                $summary = $event->blocks->first(fn ($b) => str_contains($b->block_type, 'summary'));
                if ($summary) {
                    $content = $summary->getContent();
                    $bookmark['summary'] = mb_strlen($content, 'UTF-8') > 300
                        ? mb_substr($content, 0, 300, 'UTF-8').'...'
                        : $content;
                }

                return $bookmark;
            })->values()->all();
        }

        // Fetched content (recurring fetches like Economist daily briefing)
        $fetched = $events->filter(fn ($e) => $e->service === 'fetch' && $e->action === 'fetched');
        if ($fetched->isNotEmpty()) {
            $section['fetched_content'] = $fetched->map(function ($event) {
                $item = [
                    'event_id' => $event->id,
                    'title' => $event->displayTargetTitle() ?? 'Untitled',
                    'url' => $event->displayTargetUrl(),
                ];

                // Include summary blocks (prefer short summary, fall back to any summary)
                $summary = $event->blocks->first(fn ($b) => $b->block_type === 'fetch_summary_paragraph')
                    ?? $event->blocks->first(fn ($b) => str_contains($b->block_type, 'summary'));
                if ($summary) {
                    $item['summary'] = $summary->getContent();
                }

                // Include key takeaways if available
                $takeaways = $event->blocks->firstWhere('block_type', 'fetch_key_takeaways');
                if ($takeaways) {
                    $item['key_takeaways'] = $takeaways->getContent();
                }

                // Include TLDR if available
                $tldr = $event->blocks->firstWhere('block_type', 'fetch_tldr');
                if ($tldr) {
                    $item['tldr'] = $tldr->getContent();
                }

                return $item;
            })->values()->all();
        }

        // Newsletters
        $newsletters = $events->filter(fn ($e) => $e->service === 'newsletter' && $e->action === 'received_post');
        if ($newsletters->isNotEmpty()) {
            $section['newsletters'] = $newsletters->map(function ($event) {
                $newsletter = [
                    'event_id' => $event->id,
                    'title' => $event->target?->title ?? 'Newsletter',
                    'from' => $event->actor?->title ?? 'Unknown',
                ];

                $tldr = $event->blocks->firstWhere('block_type', 'newsletter_tldr');
                if ($tldr) {
                    $newsletter['tldr'] = $tldr->getContent();
                }

                // Include summary block
                $summary = $event->blocks->first(fn ($b) => $b->block_type === 'newsletter_summary_paragraph')
                    ?? $event->blocks->first(fn ($b) => str_contains($b->block_type, 'newsletter_summary'));
                if ($summary) {
                    $newsletter['summary'] = $summary->getContent();
                }

                // Include key takeaways if available
                $takeaways = $event->blocks->firstWhere('block_type', 'newsletter_key_takeaways');
                if ($takeaways) {
                    $newsletter['key_takeaways'] = $takeaways->getContent();
                }

                return $newsletter;
            })->values()->all();
        }

        // Google Calendar events
        $calendarEvents = $events->filter(fn ($e) => $e->service === 'google_calendar' && $e->action === 'had_event');
        if ($calendarEvents->isNotEmpty()) {
            $section['calendar_events'] = $calendarEvents->map(function ($event) {
                $calEvent = [
                    'event_id' => $event->id,
                    'title' => $event->target?->title ?? 'Event',
                    'duration_minutes' => $event->formatted_value,
                    'time' => $event->time->toISOString(),
                ];

                $location = $event->blocks->firstWhere('block_type', 'event_location');
                if ($location) {
                    $calEvent['location'] = $location->getContent();
                }

                return $calEvent;
            })->values()->all();
        }

        return $section;
    }

    /**
     * Build sync status per service.
     *
     * Alongside the event-derived fields, every service now carries the
     * server's own judgement of freshness so a client never has to infer it
     * from a timestamp and a hard-coded threshold:
     *
     * - `stale` (bool) — behind for this day, using the cadence the server
     *   knows the integration runs at ({@see Integration::getUpdateFrequencyMinutes()}).
     * - `as_of` (ISO8601|null) — when the server last successfully reached the
     *   service, which is not the same as `last_event_time` (a service can be
     *   perfectly in sync and simply have nothing to report for this day).
     * - `coverage` (`'complete'|'partial'`, optional) — only set for services
     *   whose data can arrive partially within a day (currently just
     *   `apple_health`, which syncs opportunistically through the day rather
     *   than in one daily batch); absent everywhere else.
     */
    protected function buildSyncStatus(User $user, Collection $events, Carbon $localDate): array
    {
        $realTimeServices = ['apple_health'];

        $integrationsByService = $user->integrations()
            ->get(['id', 'service', 'last_successful_update_at', 'configuration'])
            ->groupBy('service');

        $servicesWithEvents = $events->groupBy('service')->map(function ($serviceEvents, $service) use ($realTimeServices, $integrationsByService, $localDate) {
            $lastEvent = $serviceEvents->sortByDesc('time')->first();
            $lastUpdated = $serviceEvents->sortByDesc('updated_at')->first();
            $status = [
                'event_count' => $serviceEvents->count(),
                'last_event_time' => $lastEvent->time->toISOString(),
                'last_updated_at' => $lastUpdated->updated_at->toISOString(),
                'freshness_basis' => 'updated_at',
                'actions' => $serviceEvents->pluck('action')->unique()->values()->all(),
            ];

            if (in_array($service, $realTimeServices)) {
                $referenceTime = $localDate->isToday() ? now() : $localDate->copy()->endOfDay();
                $hoursSinceLastUpdate = $lastUpdated->updated_at->lessThan($referenceTime)
                    ? $lastUpdated->updated_at->diffInHours($referenceTime)
                    : 0;
                $status['coverage'] = $hoursSinceLastUpdate > 2 ? 'partial' : 'complete';
                if ($hoursSinceLastUpdate > 2) {
                    $status['coverage_note'] = "Last updated {$hoursSinceLastUpdate}h ago — data may be incomplete.";
                }
            }

            [$asOf, $stale] = $this->serviceFreshness($integrationsByService->get($service));
            $status['as_of'] = $asOf?->toISOString();
            $status['stale'] = $stale;

            return $status;
        });

        // A service can be fully in sync and simply have nothing to report for
        // this particular day — that is not the same as being behind, and a
        // client can't tell the difference unless the service still appears
        // with its own stale/as_of judgement.
        $servicesWithoutEvents = $integrationsByService
            ->reject(fn ($integrations, $service) => $servicesWithEvents->has($service))
            ->map(function ($integrations) {
                [$asOf, $stale] = $this->serviceFreshness($integrations);

                return [
                    'event_count' => 0,
                    'last_event_time' => null,
                    'actions' => [],
                    'as_of' => $asOf?->toISOString(),
                    'stale' => $stale,
                ];
            });

        return $servicesWithEvents->union($servicesWithoutEvents)->all();
    }

    /**
     * Build unacknowledged anomalies for the date.
     */
    protected function buildAnomalies(User $user, Carbon $date): array
    {
        $trends = MetricTrend::query()
            ->whereHas('metricStatistic', fn ($q) => $q->where('user_id', $user->id))
            ->anomalies()
            ->unacknowledged()
            ->whereDate('detected_at', $date)
            ->with('metricStatistic')
            ->get();

        $trends = $trends->filter(function ($trend) {
            $stat = $trend->metricStatistic;
            if (! $stat) {
                return true;
            }

            $suppressionColumn = $trend->type === 'anomaly_high' ? 'anomaly_high_suppressed_until' : 'anomaly_low_suppressed_until';

            return ! ($stat->{$suppressionColumn} && now()->isBefore($stat->{$suppressionColumn}));
        });

        return $trends->map(function ($trend) {
            $stat = $trend->metricStatistic;

            // Count consecutive anomaly days for streak
            $recentAnomalies = MetricTrend::where('metric_statistic_id', $stat->id)
                ->anomalies()
                ->where('detected_at', '<=', $trend->detected_at)
                ->where('detected_at', '>=', $trend->detected_at->copy()->subDays(30))
                ->orderByDesc('detected_at')
                ->get();

            $streakCount = 0;
            $lastDate = $trend->detected_at;

            foreach ($recentAnomalies as $t) {
                $gap = $t->detected_at->diffInDays($lastDate);

                if ($gap > 1) {
                    break;
                }

                $streakCount++;
                $lastDate = $t->detected_at;
            }

            return [
                'metric' => $stat->getIdentifier(),
                'display_name' => $stat->getDisplayName(),
                'type' => $trend->type,
                'direction' => $trend->getDirection(),
                'current_value' => round($trend->current_value, 2),
                'baseline_value' => round($trend->baseline_value, 2),
                'deviation' => round($trend->deviation, 2),
                'streak_days' => $streakCount,
                'detected_at' => $trend->detected_at->toISOString(),
            ];
        })->values()->all();
    }

    /**
     * Attach baseline comparison data to an entry array.
     */
    protected function attachBaseline(array &$entry, Event $event, array $metricsCache): void
    {
        $entry['observed_at'] = $event->time?->toIso8601String();
        $entry['updated_at'] = $event->updated_at?->toIso8601String();
        $entry['state'] = $this->summaryDateIsToday ? 'provisional' : 'settled';

        if ($event->value === null || $event->value_unit === null) {
            return;
        }

        $metricKey = "{$event->service}.{$event->action}.{$event->value_unit}";

        if (! isset($metricsCache[$metricKey])) {
            return;
        }

        $statistic = $metricsCache[$metricKey]['statistic'];
        $currentValue = $event->formatted_value;
        $presentation = $this->presentation();

        $entry['is_anomaly'] = $presentation->isAnomalous($statistic, $currentValue);

        // Ordinal metrics get their band, not a percentage against a
        // fractional mean — see MetricPresentation::baselineDeltaPct().
        if ($presentation->isOrdinal($statistic)) {
            $entry['is_ordinal'] = true;
            $entry['band'] = $presentation->formatValue($statistic, $currentValue);
            $entry['usual_band'] = $presentation->formatValue($statistic, round((float) $statistic->mean_value));

            return;
        }

        $entry['vs_baseline_pct'] = $presentation->baselineDeltaPct($statistic, $currentValue);
    }

    /**
     * Check if action type should be excluded.
     */
    protected function shouldExcludeAction(string $service, string $action): bool
    {
        $plugin = PluginRegistry::getPlugin($service);
        if (! $plugin) {
            return false;
        }

        $actionTypes = $plugin::getActionTypes();
        if (! isset($actionTypes[$action])) {
            return false;
        }

        return $actionTypes[$action]['exclude_from_flint'] ?? false;
    }

    /**
     * The server's own freshness judgement for a service: when it last
     * reached it successfully, and whether that is behind the cadence it
     * knows that integration runs at. A service with no successful sync yet
     * is stale by definition.
     *
     * @param  Collection<int, Integration>|null  $integrations
     * @return array{0: Carbon|null, 1: bool}
     */
    private function serviceFreshness(?Collection $integrations): array
    {
        if ($integrations === null || $integrations->isEmpty()) {
            return [null, true];
        }

        $asOf = $integrations->max('last_successful_update_at');

        if ($asOf === null) {
            return [null, true];
        }

        // A generous multiple of the integration's own polling cadence, so
        // ordinary scheduling jitter never reads as staleness, floored at an
        // hour for integrations configured with a very tight cadence.
        $cadenceMinutes = max($integrations->max(fn (Integration $i) => $i->getUpdateFrequencyMinutes()), 15);
        $staleAfterMinutes = max($cadenceMinutes * 4, 60);

        return [$asOf, $asOf->diffInMinutes(now()) > $staleAfterMinutes];
    }

    /**
     * A day-level baseline for the user's total daily spend, computed
     * dynamically over their own history rather than stored — `MetricStatistic`
     * is computed per event value, which is the same thing as a day baseline
     * only for metrics that emit once a day; `money.total_spend` emits many
     * times a day and needs its own aggregate.
     *
     * Cached briefly since it scans up to 60 days of money events; the day
     * that just changed the baseline can lag by that long without materially
     * changing the mean.
     *
     * @return array{mean: float, count: int}|null null when there isn't
     *                                             enough history yet for a
     *                                             meaningful baseline.
     */
    private function dailySpendBaseline(User $user): ?array
    {
        $daily = Cache::remember(
            "day_summary.money_baseline.{$user->id}",
            now()->addHours(6),
            function () use ($user): Collection {
                $windowStart = now()->subDays(60)->startOfDay();
                $windowEnd = now()->startOfDay();

                $events = Event::query()
                    ->withoutInternal()
                    ->whereHas('integration', fn ($q) => $q->where('user_id', $user->id))
                    ->where('domain', 'money')
                    ->whereNotNull('value')
                    ->whereBetween('time', [$windowStart, $windowEnd])
                    ->with(['actor', 'target'])
                    ->get();

                return $events
                    ->filter(fn (Event $e) => MoneyDirection::for($e) === MoneyDirection::OUT)
                    ->groupBy(fn (Event $e) => $e->time->toDateString())
                    ->map(fn (Collection $dayEvents) => $dayEvents->sum(fn (Event $e) => abs($e->formatted_value)));
            }
        );

        // Fewer than a week of days with any spend isn't enough to call a mean
        // meaningful yet — report the reason rather than a noisy percentage.
        if ($daily->count() < 5) {
            return null;
        }

        return [
            'mean' => round((float) $daily->avg(), 2),
            'count' => $daily->count(),
        ];
    }

    /**
     * `attachBaseline()` runs once per event, so resolve the presenter once
     * rather than hitting the container on every row.
     */
    private function presentation(): MetricPresentation
    {
        return $this->presentation ??= app(MetricPresentation::class);
    }
}
