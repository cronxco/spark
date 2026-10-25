<?php

namespace App\Services\ManualLog;

use App\Integrations\ManualLog\ManualLogPlugin;
use App\Integrations\ManualLog\NameMatcher;
use App\Integrations\ManualLog\TvdbClient;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\Person;
use App\Models\Relationship;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Turns a structured "log this" request (extracted from free text on the
 * phone) into Manual Log events.
 *
 * Wine and beer are written straight away and enriched afterwards by queued
 * Playwright lookups. TV episodes are resolved against TheTVDB first, because
 * the season is often missing or ambiguous and the caller needs to ask.
 */
class ManualLogService
{
    /** Request kind => Manual Log action. */
    public const KINDS = [
        'wine' => 'drank_wine',
        'beer' => 'drank_beer',
        'tv' => 'watched_episode',
        'cinema' => 'watched_at_cinema',
        'board_game' => 'played_board_game',
        'reflection' => 'logged_reflection',
    ];

    private const MAX_SEASON_OPTIONS = 8;

    public function __construct(
        protected TvdbClient $tvdb,
        protected ManualLogLookup $lookup,
        protected PersonMatcher $personMatcher,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated StoreLogRequest input
     * @return array{status: 'logged', created: bool, events: array<int, Event>, missing_episodes?: array<int, int>, people?: array<int, array{id: string, name: string, created: bool}>}|array{status: 'needs_clarification', field: string, question: string, options: array<int, array<string, mixed>>, suggested?: mixed}
     *
     * @throws InvalidArgumentException When a requested episode does not exist
     */
    public function log(User $user, array $data): array
    {
        $integration = ManualLogPlugin::resolveIntegration($user->id);

        $result = $data['kind'] === 'tv'
            ? $this->logTv($integration, $data)
            : $this->logSimple($integration, $data);

        if ($result['status'] === 'logged' && (! empty($data['with']) || ! empty($data['with_ids']))) {
            $result['people'] = $this->tagPeople($user, $result['events'], $data['with'] ?? [], $data['with_ids'] ?? []);
        }

        return $result;
    }

    /**
     * What logging this would match and enrich, without writing anything.
     *
     * Wine and beer search Vivino or Untappd now (kept for a few minutes so the log that follows reuses it).
     * TV asks TheTVDB the same questions a real log would, including which series or season.
     *
     * @param  array<string, mixed>  $data  Validated StoreLogRequest input
     * @return array{status: 'preview', match: array<string, mixed>|null, people: array<int, array<string, mixed>>}|array{status: 'needs_clarification', field: string, question: string, options: array<int, array<string, mixed>>, suggested?: mixed, people: array<int, array<string, mixed>>}
     */
    public function preview(User $user, array $data): array
    {
        return $this->previewMatch($user, $data) + [
            'people' => $this->personMatcher->check((string) $user->id, $data['with'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function previewMatch(User $user, array $data): array
    {
        if (in_array($data['kind'], ['wine', 'beer'], true)) {
            $integration = ManualLogPlugin::resolveIntegration($user->id);
            $cards = $this->lookup->results($data['kind'], $data['title'], $integration->group);

            return ['status' => 'preview', 'match' => ['kind' => $data['kind']] + $this->lookup->describe($cards, $data['title'])];
        }

        if ($data['kind'] !== 'tv') {
            return ['status' => 'preview', 'match' => null];
        }

        return $this->previewTv($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function previewTv(array $data): array
    {
        $unavailable = ['status' => 'preview', 'match' => ['kind' => 'tv', 'state' => 'unavailable']];

        if (! $this->tvdb->isConfigured()) {
            return $unavailable;
        }

        try {
            $series = $this->resolveSeries($data);
            if (isset($series['status'])) {
                return $series;
            }

            $season = isset($data['season']) ? (int) $data['season'] : $this->resolveSeason($series['id']);
            if (is_array($season)) {
                return $season;
            }
            if ($season === null) {
                return ['status' => 'preview', 'match' => ['kind' => 'tv', 'state' => 'matched', 'series' => $series, 'season' => null, 'episodes' => [], 'missing_episodes' => []]];
            }

            $available = collect($this->tvdb->episodes($series['id'], $season))->keyBy('number');
        } catch (InvalidArgumentException $e) {
            return ['status' => 'preview', 'match' => ['kind' => 'tv', 'state' => 'none']];
        } catch (Throwable $e) {
            Log::warning('TV preview: TheTVDB lookup failed', ['title' => $data['title'], 'error' => $e->getMessage()]);

            return $unavailable;
        }

        $wanted = collect($data['episodes'])->map(fn ($number) => (int) $number)->unique()->sort()->values();

        return ['status' => 'preview', 'match' => [
            'kind' => 'tv',
            'state' => 'matched',
            'series' => $series,
            'season' => $season,
            'episodes' => $wanted->filter(fn (int $number) => $available->has($number))
                ->map(fn (int $number) => ['number' => $number, 'name' => $available->get($number)['name'], 'aired' => $available->get($number)['aired']])
                ->values()->all(),
            'missing_episodes' => $wanted->reject(fn (int $number) => $available->has($number))->values()->all(),
        ]];
    }

    /**
     * Links each person to the events with a `with` relationship. People the app already matched arrive by id and are
     * never renamed or duplicated; named people are found by exact title, and created when not seen before.
     *
     * @param  array<int, Event>  $events
     * @param  array<int, string>  $names
     * @param  array<int, string>  $ids  Person ids the app settled on; ids that are not this user's people are ignored
     * @return array<int, array{id: string, name: string, created: bool}>
     */
    private function tagPeople(User $user, array $events, array $names, array $ids = []): array
    {
        $people = [];
        $linked = [];

        $matched = $ids === [] ? collect() : Person::query()->where('user_id', $user->id)->whereIn('id', $ids)->get();

        $targets = $matched->map(fn (Person $person) => [$person, false])->all();

        foreach (collect($names)->map(fn (string $name) => trim($name))->filter(fn (string $name) => $name !== '' && ! PersonMatcher::isReserved($name))->unique(fn (string $name) => Str::lower($name)) as $name) {
            $person = Person::query()
                ->where('user_id', $user->id)
                ->whereRaw('lower(title) = ?', [Str::lower($name)])
                ->first();
            $created = $person === null;

            $person ??= Person::create([
                'user_id' => $user->id,
                'type' => 'person',
                'title' => $name,
                'time' => now(),
                'metadata' => [],
            ]);

            $targets[] = [$person, $created];
        }

        foreach ($targets as [$person, $created]) {
            if (isset($linked[(string) $person->id])) {
                continue;
            }
            $linked[(string) $person->id] = true;

            foreach ($events as $event) {
                Relationship::createRelationship([
                    'user_id' => $user->id,
                    'from_type' => EventObject::class,
                    'from_id' => $person->id,
                    'to_type' => Event::class,
                    'to_id' => $event->id,
                    'type' => 'with',
                ]);
            }

            $people[] = ['id' => (string) $person->id, 'name' => $person->title, 'created' => $created];
        }

        return $people;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function logSimple(Integration $integration, array $data): array
    {
        $sourceId = $this->sourceId($data['idempotency_key']);
        $reflection = $data['kind'] === 'reflection';

        if ($existing = $this->existing($integration, [$sourceId])) {
            return ['status' => 'logged', 'created' => false, 'events' => $existing];
        }

        // Undone since: a late retry must not write it again.
        if (Event::onlyTrashed()->where('integration_id', $integration->id)->where('source_id', $sourceId)->exists()) {
            return ['status' => 'logged', 'created' => false, 'events' => []];
        }

        if ($reflection) {
            $data['title'] = $data['title'] ?? Str::limit(Str::before(trim($data['body']), "\n"), 80, '…');
        }

        try {
            $event = (new ManualLogPlugin)->createManualEvent(
                $integration,
                self::KINDS[$data['kind']],
                $data['title'],
                isset($data['rating']) ? (float) $data['rating'] : null,
                $data['notes'] ?? null,
                [
                    'occurred_at' => $this->occurredAt($data),
                    'source_id' => $sourceId,
                    'event_metadata' => $this->consumptionMetadata($data),
                    'match_url' => $data['match_url'] ?? null,
                    'content' => $reflection ? $data['body'] : null,
                ],
            );
        } catch (UniqueConstraintViolationException $e) {
            return ['status' => 'logged', 'created' => false, 'events' => $this->existing($integration, [$sourceId])];
        }

        return ['status' => 'logged', 'created' => true, 'events' => [$event]];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function logTv(Integration $integration, array $data): array
    {
        $episodes = collect($data['episodes'])->map(fn ($number) => (int) $number)->unique()->sort()->values()->all();
        $sourceIds = array_map(fn (int $number) => $this->sourceId($data['idempotency_key'], "e{$number}"), $episodes);

        if ($existing = $this->existing($integration, $sourceIds)) {
            return $this->replayTv($data, $episodes, $existing);
        }

        $series = null;
        $season = isset($data['season']) ? (int) $data['season'] : null;

        if ($this->tvdb->isConfigured()) {
            try {
                $resolved = $this->resolveSeries($data);
                if (isset($resolved['status'])) {
                    return $resolved;
                }

                $series = $resolved;
                $season ??= $this->resolveSeason($series['id']);
                if (is_array($season)) {
                    return $season;
                }
            } catch (InvalidArgumentException $e) {
                throw $e;
            } catch (Throwable $e) {
                Log::warning('TV log: TheTVDB lookup failed, logging without enrichment', [
                    'title' => $data['title'],
                    'error' => $e->getMessage(),
                ]);
                $series = null;
            }
        }

        if ($series === null || $season === null) {
            return $this->logThinEpisodes($integration, $data, $episodes);
        }

        try {
            $available = collect($this->tvdb->episodes($series['id'], $season))->keyBy('number');
        } catch (Throwable $e) {
            Log::warning('TV log: TheTVDB episode lookup failed, logging without enrichment', [
                'title' => $data['title'],
                'error' => $e->getMessage(),
            ]);

            return $this->logThinEpisodes($integration, ['season' => $season] + $data, $episodes);
        }

        $missing = array_values(array_diff($episodes, $available->keys()->all()));

        if (count($missing) === count($episodes)) {
            throw new InvalidArgumentException("{$series['name']} season {$season} has no episode " . implode(', ', $missing) . '.');
        }

        try {
            $events = DB::transaction(function () use ($episodes, $available, $integration, $data, $series, $season) {
                $events = [];
                foreach ($episodes as $number) {
                    if ($available->has($number)) {
                        $events[] = $this->createEpisode($integration, $data, $series, $season, $available->get($number));
                    }
                }

                return $events;
            });
        } catch (UniqueConstraintViolationException $e) {
            return $this->replayTv($data, $episodes, $this->existing($integration, $sourceIds));
        }

        return ['status' => 'logged', 'created' => true, 'events' => $events] + ($missing ? ['missing_episodes' => $missing] : []);
    }

    /**
     * The response for a retried TV request: the events already written, and any requested episode with no event.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, int>  $episodes
     * @param  array<int, Event>  $existing
     */
    private function replayTv(array $data, array $episodes, array $existing): array
    {
        $found = array_map(fn (Event $event) => $event->source_id, $existing);
        $missing = array_values(array_filter(
            $episodes,
            fn (int $number) => ! in_array($this->sourceId($data['idempotency_key'], "e{$number}"), $found, true),
        ));

        return ['status' => 'logged', 'created' => false, 'events' => $existing] + ($missing ? ['missing_episodes' => $missing] : []);
    }

    /**
     * The matching TheTVDB series, or a clarification when it is ambiguous.
     *
     * @param  array<string, mixed>  $data
     * @return array{id: int, name: string}|array{status: 'needs_clarification', field: string, question: string, options: array<int, array<string, mixed>>}
     */
    private function resolveSeries(array $data): array
    {
        if (isset($data['tvdb_id'])) {
            return ['id' => (int) $data['tvdb_id'], 'name' => $data['title']];
        }

        $results = $this->tvdb->searchSeries($data['title']);
        $name = fn (array $result) => $result['name'];

        $best = NameMatcher::best($results, $data['title'], $name);
        if ($best !== null) {
            $twins = array_filter($results, fn (array $result) => $result['id'] !== $best['id']
                && NameMatcher::score($best['name'], $result['name']) === 1.0
                && NameMatcher::score($result['name'], $best['name']) === 1.0);

            if ($twins === []) {
                return ['id' => $best['id'], 'name' => $best['name']];
            }

            $candidates = array_merge([$best], array_values($twins));
        } else {
            $candidates = NameMatcher::candidates($results, $data['title'], $name);
        }

        if ($candidates === []) {
            throw new InvalidArgumentException('No TV series found for that title.');
        }

        return [
            'status' => 'needs_clarification',
            'field' => 'series',
            'question' => "Which \"{$data['title']}\"?",
            'options' => array_map(fn (array $series) => [
                'label' => trim($series['name'] . ($series['year'] ? " ({$series['year']})" : '')),
                'value' => $series['id'],
                'name' => $series['name'],
                'overview' => $series['overview'],
                'image' => $series['image'],
            ], array_slice($candidates, 0, 5)),
        ];
    }

    /**
     * The season to log against: the only one, or a clarification asking which.
     *
     * @return int|array{status: 'needs_clarification', field: string, question: string, options: array<int, array<string, mixed>>, suggested: int}|null
     */
    private function resolveSeason(int $seriesId): int|array|null
    {
        $seasons = $this->tvdb->seasons($seriesId);

        if ($seasons === []) {
            return null;
        }

        if (count($seasons) === 1) {
            return $seasons[0];
        }

        $latestFirst = array_slice(array_reverse($seasons), 0, self::MAX_SEASON_OPTIONS);

        return [
            'status' => 'needs_clarification',
            'field' => 'season',
            'question' => 'Which season?',
            'options' => array_map(fn (int $number) => ['label' => "Season {$number}", 'value' => $number], $latestFirst),
            'suggested' => $latestFirst[0],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{id: int, name: string}  $series
     * @param  array{id: int, number: int, name: ?string, aired: ?string, runtime: ?int, image: ?string}  $episode
     */
    private function createEpisode(Integration $integration, array $data, array $series, int $season, array $episode): Event
    {
        $code = sprintf('S%02dE%02d', $season, $episode['number']);

        return (new ManualLogPlugin)->createManualEvent(
            $integration,
            'watched_episode',
            "{$series['name']} {$code}",
            isset($data['rating']) ? (float) $data['rating'] : null,
            $data['notes'] ?? null,
            [
                'occurred_at' => $this->occurredAt($data),
                'source_id' => $this->sourceId($data['idempotency_key'], "e{$episode['number']}"),
                'target_metadata' => array_filter([
                    'series' => $series['name'],
                    'season' => $season,
                    'episode' => $episode['number'],
                    'episode_name' => $episode['name'],
                    'aired' => $episode['aired'],
                    'runtime_minutes' => $episode['runtime'],
                    'tvdb_series_id' => $series['id'],
                    'tvdb_episode_id' => $episode['id'],
                ], fn ($value) => $value !== null),
                'media_url' => $episode['image'],
                'enrich' => false,
            ],
        );
    }

    /**
     * Episodes logged without TheTVDB detail: the show title and episode number only.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, int>  $episodes
     */
    private function logThinEpisodes(Integration $integration, array $data, array $episodes): array
    {
        $events = array_map(fn (int $number) => (new ManualLogPlugin)->createManualEvent(
            $integration,
            'watched_episode',
            isset($data['season'])
                ? sprintf('%s S%02dE%02d', $data['title'], $data['season'], $number)
                : "{$data['title']} E{$number}",
            isset($data['rating']) ? (float) $data['rating'] : null,
            $data['notes'] ?? null,
            [
                'occurred_at' => $this->occurredAt($data),
                'source_id' => $this->sourceId($data['idempotency_key'], "e{$number}"),
                'enrich' => false,
            ],
        ), $episodes);

        return ['status' => 'logged', 'created' => true, 'events' => $events];
    }

    /**
     * How much was consumed, kept on the event so alcohol units can be worked out once the beer's ABV is known.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function consumptionMetadata(array $data): array
    {
        return array_filter([
            'quantity' => $data['quantity'] ?? null,
            'unit' => $data['unit'] ?? null,
            'serving_style' => $data['serving'] ?? null,
            'serving_ml' => $data['volume_ml'] ?? null,
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function occurredAt(array $data): CarbonImmutable
    {
        return isset($data['occurred_at']) ? CarbonImmutable::parse($data['occurred_at']) : CarbonImmutable::now();
    }

    private function sourceId(string $idempotencyKey, ?string $suffix = null): string
    {
        return 'manual_log_' . $idempotencyKey . ($suffix ? '_' . $suffix : '');
    }

    /**
     * Events already written for these source ids (a retried request).
     *
     * @param  array<int, string>  $sourceIds
     * @return array<int, Event>
     */
    private function existing(Integration $integration, array $sourceIds): array
    {
        return Event::with('target')
            ->where('integration_id', $integration->id)
            ->whereIn('source_id', $sourceIds)
            ->get()
            ->all();
    }
}
