<?php

namespace App\Integrations\ManualLog;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Minimal TheTVDB v4 client for resolving a logged show and its episodes.
 *
 * Written from TheTVDB's public v4 API docs and covered by Http::fake tests;
 * it has not been run against the live API (no key was available when it was
 * written). Requires services.tvdb.api_key.
 */
class TvdbClient
{
    private const TOKEN_CACHE_KEY = 'tvdb.token';

    public function isConfigured(): bool
    {
        return filled(config('services.tvdb.api_key'));
    }

    /**
     * @return array<int, array{id: int, name: string, year: ?string, overview: ?string, image: ?string}>
     */
    public function searchSeries(string $query): array
    {
        $response = $this->get('/search', ['query' => $query, 'type' => 'series', 'limit' => 10]);

        return collect($response['data'] ?? [])
            ->filter(fn (array $item) => isset($item['tvdb_id'], $item['name']))
            ->map(fn (array $item) => [
                'id' => (int) $item['tvdb_id'],
                'name' => (string) $item['name'],
                'year' => isset($item['year']) ? (string) $item['year'] : null,
                'overview' => $item['overview'] ?? null,
                'image' => $item['image_url'] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * Aired season numbers for a series, specials (season 0) excluded, ascending.
     *
     * @return array<int, int>
     */
    public function seasons(int $seriesId): array
    {
        $response = $this->get("/series/{$seriesId}/extended", ['short' => 'true']);

        return collect($response['data']['seasons'] ?? [])
            ->filter(fn (array $season) => ($season['type']['type'] ?? null) === 'official' && ($season['number'] ?? 0) > 0)
            ->pluck('number')
            ->map(fn ($number) => (int) $number)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Episodes of one season, in the series' default ordering.
     *
     * @return array<int, array{id: int, number: int, name: ?string, aired: ?string, runtime: ?int, image: ?string}>
     */
    public function episodes(int $seriesId, int $season): array
    {
        $response = $this->get("/series/{$seriesId}/episodes/default", ['season' => $season, 'page' => 0]);

        return collect($response['data']['episodes'] ?? [])
            ->filter(fn (array $episode) => ($episode['seasonNumber'] ?? null) === $season && isset($episode['number']))
            ->map(fn (array $episode) => [
                'id' => (int) $episode['id'],
                'number' => (int) $episode['number'],
                'name' => $episode['name'] ?? null,
                'aired' => $episode['aired'] ?? null,
                'runtime' => isset($episode['runtime']) ? (int) $episode['runtime'] : null,
                'image' => $episode['image'] ?? null,
            ])
            ->sortBy('number')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    private function get(string $path, array $query = []): array
    {
        $response = $this->request()->get($path, $query);

        if ($response->status() === 401) {
            Cache::forget(self::TOKEN_CACHE_KEY);
            $response = $this->request()->get($path, $query);
        }

        return $response->throw()->json() ?? [];
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(config('services.tvdb.base_url'))
            ->acceptJson()
            ->timeout(10)
            ->withToken($this->token());
    }

    private function token(): string
    {
        return Cache::remember(self::TOKEN_CACHE_KEY, now()->addDays(25), function (): string {
            $response = Http::baseUrl(config('services.tvdb.base_url'))
                ->acceptJson()
                ->timeout(10)
                ->post('/login', ['apikey' => config('services.tvdb.api_key')])
                ->throw();

            return (string) $response->json('data.token');
        });
    }
}
