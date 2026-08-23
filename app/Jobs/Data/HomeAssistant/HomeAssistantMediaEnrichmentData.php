<?php

namespace App\Jobs\Data\HomeAssistant;

use App\Jobs\Base\BaseProcessingJob;
use App\Models\Event;
use App\Services\AssistantPromptingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class HomeAssistantMediaEnrichmentData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'home_assistant';
    }

    protected function getJobType(): string
    {
        return 'media_enrichment';
    }

    protected function process(): void
    {
        $eventId = $this->rawData['event_id'] ?? null;
        $candidates = $this->rawData['candidates'] ?? [];

        if (! $eventId) {
            return;
        }

        $event = Event::with('target')->find($eventId);

        if (! $event || ! $event->target) {
            return;
        }

        $title = $this->rawData['title'] ?? $event->target->title;

        $match = count($candidates) === 1
            ? $candidates[0]
            : $this->disambiguate($title, $candidates);

        if ($match === null) {
            Log::info('Home Assistant media enrichment: no confident match', [
                'event_id' => $eventId,
                'title' => $this->rawData['title'] ?? null,
            ]);

            return;
        }

        $this->applyMatch($event, $match);
    }

    /**
     * Ask the LLM to pick the right TMDB candidate (or reject all of them,
     * e.g. live sport/news that happened to loosely title-match something),
     * giving it a live search_tmdb tool so it can retry with a cleaned-up
     * title (stripping "Live: ", channel branding, etc.) rather than being
     * stuck with whatever the Pull job's upfront search happened to find.
     */
    private function disambiguate(string $title, array $candidates): ?array
    {
        $apiKey = config('services.tmdb.api_key');

        // Seeds the id->candidate cache with the Pull job's initial search
        // so a direct answer (no tool call needed) can still be resolved.
        $cache = [];
        foreach ($candidates as $candidate) {
            if (isset($candidate['id'])) {
                $cache[$candidate['id']] = $candidate;
            }
        }

        $toolExecutor = function (string $name, array $arguments) use (&$cache, $apiKey) {
            if ($name !== 'search_tmdb') {
                return ['results' => []];
            }

            $results = $this->searchTmdb($arguments['query'] ?? '', $apiKey);

            foreach ($results as $result) {
                if (isset($result['id'])) {
                    $cache[$result['id']] = $result;
                }
            }

            return ['results' => $this->summarizeCandidates($results)];
        };

        try {
            $response = app(AssistantPromptingService::class)->generateResponse(
                $this->buildDisambiguationPrompt($title, $candidates),
                [
                    'model' => config('services.openai.models.gpt5_mini'),
                    'context' => ['prompt_type' => 'home_assistant_media_disambiguation'],
                    'max_completion_tokens' => 300,
                    'temperature' => 0,
                    'tools' => $this->searchTmdbToolDefinition(),
                    'tool_executor' => $toolExecutor,
                ]
            );
        } catch (Throwable $e) {
            Log::warning('Home Assistant media enrichment: disambiguation call failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $decoded = json_decode(trim($response), true);
        $tmdbId = $decoded['tmdb_id'] ?? null;

        if ($tmdbId === null || ! isset($cache[$tmdbId])) {
            return null;
        }

        return $cache[$tmdbId];
    }

    private function buildDisambiguationPrompt(string $title, array $candidates): string
    {
        $candidateText = empty($candidates)
            ? 'The initial search returned no results.'
            : "Initial search results:\n" . implode("\n", $this->summarizeCandidates($candidates));

        return <<<PROMPT
            A Home Assistant media player just reported watching something titled "{$title}".

            {$candidateText}

            If one of the initial results is a confident match, answer with its id directly.
            Otherwise, use the search_tmdb tool to retry with a cleaned-up query (e.g.
            stripping "Live: ", channel branding, or other noise) - you may call it more than
            once.

            Once you're confident, reply with ONLY a JSON object: {"tmdb_id": <id>} for the
            correct match, or {"tmdb_id": null} if this is not a genuine film/TV title (e.g.
            live sport, news, or a menu screen).
            PROMPT;
    }

    /**
     * @return array<int, array{type: string, function: array}>
     */
    private function searchTmdbToolDefinition(): array
    {
        return [[
            'type' => 'function',
            'function' => [
                'name' => 'search_tmdb',
                'description' => 'Search TMDB (The Movie Database) for movies and TV shows by title. Use this to retry with a cleaned-up query if the initial results do not contain a confident match.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'The search query to send to TMDB.',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
        ]];
    }

    /**
     * @return array<int, array>
     */
    private function searchTmdb(string $query, ?string $apiKey): array
    {
        if ($query === '' || empty($apiKey)) {
            return [];
        }

        $response = Http::get(config('services.tmdb.base_url') . '/search/multi', [
            'api_key' => $apiKey,
            'query' => $query,
            'include_adult' => false,
        ]);

        if (! $response->successful()) {
            return [];
        }

        return collect($response->json('results') ?? [])
            ->filter(fn (array $result) => in_array($result['media_type'] ?? null, ['movie', 'tv'], true))
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function summarizeCandidates(array $candidates): array
    {
        return collect($candidates)->map(function (array $candidate) {
            $id = $candidate['id'] ?? 'unknown';
            $name = $candidate['title'] ?? $candidate['name'] ?? 'Unknown';
            $date = $candidate['release_date'] ?? $candidate['first_air_date'] ?? null;
            $year = $date ? substr($date, 0, 4) : 'unknown year';
            $type = ($candidate['media_type'] ?? null) === 'tv' ? 'TV show' : 'movie';
            $overview = str($candidate['overview'] ?? '')->limit(150);

            return "- [{$id}] \"{$name}\" ({$year}, {$type}) - {$overview}";
        })->all();
    }

    private function applyMatch(Event $event, array $match): void
    {
        $isTv = ($match['media_type'] ?? null) === 'tv';
        $title = $match['title'] ?? $match['name'] ?? $event->target->title;
        $posterPath = $match['poster_path'] ?? null;
        $mediaUrl = $posterPath ? config('services.tmdb.image_base_url') . $posterPath : null;

        $event->target->update([
            'type' => $isTv ? 'tv_episode' : 'movie',
            'content' => $match['overview'] ?? null,
            'media_url' => $mediaUrl,
            'metadata' => array_merge($event->target->metadata ?? [], array_filter([
                'tmdb_id' => $match['id'] ?? null,
                'media_type' => $match['media_type'] ?? null,
                'release_date' => $match['release_date'] ?? $match['first_air_date'] ?? null,
                'genre_ids' => $match['genre_ids'] ?? null,
                'popularity' => $match['popularity'] ?? null,
                'vote_average' => $match['vote_average'] ?? null,
            ], fn ($value) => $value !== null)),
        ]);

        $event->createBlock([
            'block_type' => 'media_details',
            'title' => $title,
            'metadata' => array_filter([
                'overview' => $match['overview'] ?? null,
                'release_date' => $match['release_date'] ?? $match['first_air_date'] ?? null,
                'vote_average' => $match['vote_average'] ?? null,
            ], fn ($value) => $value !== null),
            'value' => isset($match['vote_average']) ? (int) round($match['vote_average'] * 10) : null,
            'value_multiplier' => isset($match['vote_average']) ? 10 : 1,
            'value_unit' => isset($match['vote_average']) ? '/10' : null,
        ]);
    }
}
