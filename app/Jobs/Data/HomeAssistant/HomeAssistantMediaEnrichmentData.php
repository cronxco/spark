<?php

namespace App\Jobs\Data\HomeAssistant;

use App\Jobs\Base\BaseProcessingJob;
use App\Models\Event;
use App\Services\AssistantPromptingService;
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

        if (! $eventId || empty($candidates)) {
            return;
        }

        $event = Event::with('target')->find($eventId);

        if (! $event || ! $event->target) {
            return;
        }

        $match = count($candidates) === 1
            ? $candidates[0]
            : $this->disambiguate($this->rawData['title'] ?? $event->target->title, $candidates);

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
     * e.g. live sport/news that happened to loosely title-match something).
     */
    private function disambiguate(string $title, array $candidates): ?array
    {
        $lines = collect($candidates)->map(function (array $candidate, int $index) {
            $name = $candidate['title'] ?? $candidate['name'] ?? 'Unknown';
            $date = $candidate['release_date'] ?? $candidate['first_air_date'] ?? null;
            $year = $date ? substr($date, 0, 4) : 'unknown year';
            $type = ($candidate['media_type'] ?? null) === 'tv' ? 'TV show' : 'movie';
            $overview = str($candidate['overview'] ?? '')->limit(150);

            return "{$index}. \"{$name}\" ({$year}, {$type}) - {$overview}";
        })->implode("\n");

        $prompt = <<<PROMPT
            A Home Assistant media player just reported watching something titled "{$title}".
            Here are the possible TMDB matches:

            {$lines}

            Reply with ONLY a JSON object: {"match_index": <number>} for the correct match,
            or {"match_index": null} if none of these are a genuine film/TV match (e.g. the
            title is actually live sport, news, or a menu screen, not one of these titles).
            PROMPT;

        try {
            $response = app(AssistantPromptingService::class)->generateResponse($prompt, [
                'model' => config('services.openai.models.gpt5_mini'),
                'context' => ['prompt_type' => 'home_assistant_media_disambiguation'],
                'max_completion_tokens' => 100,
                'temperature' => 0,
            ]);
        } catch (Throwable $e) {
            Log::warning('Home Assistant media enrichment: disambiguation call failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $decoded = json_decode(trim($response), true);
        $index = $decoded['match_index'] ?? null;

        if ($index === null || ! isset($candidates[$index])) {
            return null;
        }

        return $candidates[$index];
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
