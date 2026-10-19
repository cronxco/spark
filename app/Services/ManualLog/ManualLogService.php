<?php

namespace App\Services\ManualLog;

use App\Integrations\ManualLog\ManualLogPlugin;
use App\Integrations\ManualLog\NameMatcher;
use App\Integrations\ManualLog\TvdbClient;
use App\Models\Event;
use App\Models\Integration;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
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
    ];

    private const MAX_SEASON_OPTIONS = 8;

    public function __construct(
        protected TvdbClient $tvdb,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated StoreLogRequest input
     * @return array{status: 'logged', created: bool, events: array<int, Event>, missing_episodes?: array<int, int>}|array{status: 'needs_clarification', field: string, question: string, options: array<int, array<string, mixed>>, suggested?: mixed}
     *
     * @throws InvalidArgumentException When a requested episode does not exist
     */
    public function log(User $user, array $data): array
    {
        $integration = ManualLogPlugin::resolveIntegration($user->id);

        return $data['kind'] === 'tv'
            ? $this->logTv($integration, $data)
            : $this->logSimple($integration, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function logSimple(Integration $integration, array $data): array
    {
        $sourceId = $this->sourceId($data['idempotency_key']);

        if ($existing = $this->existing($integration, [$sourceId])) {
            return ['status' => 'logged', 'created' => false, 'events' => $existing];
        }

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
            ],
        );

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
            return ['status' => 'logged', 'created' => false, 'events' => $existing];
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

        $events = [];
        foreach ($episodes as $number) {
            if (! $available->has($number)) {
                continue;
            }

            $events[] = $this->createEpisode($integration, $data, $series, $season, $available->get($number));
        }

        return ['status' => 'logged', 'created' => true, 'events' => $events] + ($missing ? ['missing_episodes' => $missing] : []);
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
     * Events already written for these source ids, when every one of them exists (a retried request).
     *
     * @param  array<int, string>  $sourceIds
     * @return array<int, Event>
     */
    private function existing(Integration $integration, array $sourceIds): array
    {
        $events = Event::with('target')
            ->where('integration_id', $integration->id)
            ->whereIn('source_id', $sourceIds)
            ->get();

        return $events->count() === count($sourceIds) ? $events->all() : [];
    }
}
