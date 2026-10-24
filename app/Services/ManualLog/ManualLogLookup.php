<?php

namespace App\Services\ManualLog;

use App\Integrations\Fetch\PlaywrightFetchClient;
use App\Integrations\ManualLog\NameMatcher;
use App\Integrations\ManualLog\UntappdSearchParser;
use App\Integrations\ManualLog\VivinoSearchParser;
use App\Models\IntegrationGroup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Searches Vivino or Untappd for a logged wine or beer.
 *
 * The preview (dry run) and the enrichment job that follows a real log both
 * ask the same question, so a successful search is kept for a few minutes and
 * logging right after previewing does not fetch the page a second time.
 */
class ManualLogLookup
{
    private const CACHE_MINUTES = 10;

    /**
     * Search result cards for the name, best first as the site ranks them. Null when the site could not be searched.
     *
     * @param  'wine'|'beer'  $kind
     * @return array<int, array<string, mixed>>|null
     */
    public function results(string $kind, string $name, IntegrationGroup $group): ?array
    {
        $key = 'manual_log.lookup.' . $kind . '.' . sha1(mb_strtolower(trim($name)));

        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $searchUrl = config($kind === 'wine' ? 'services.vivino.search_url' : 'services.untappd.search_url')
            . '?' . http_build_query(['q' => $name]);

        try {
            $result = app(PlaywrightFetchClient::class)->fetch($searchUrl, $group);
        } catch (Throwable $e) {
            Log::warning('Manual log: ' . $kind . ' search failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (! ($result['success'] ?? false) || empty($result['html'])) {
            return null;
        }

        $cards = $kind === 'wine'
            ? app(VivinoSearchParser::class)->parseResults($result['html'])
            : app(UntappdSearchParser::class)->parseResults($result['html']);

        Cache::put($key, $cards, now()->addMinutes(self::CACHE_MINUTES));

        return $cards;
    }

    /**
     * The card the user picked by its URL, otherwise the one that covers enough of the name.
     *
     * @param  array<int, array<string, mixed>>  $cards
     * @return array<string, mixed>|null
     */
    public function pick(array $cards, string $name, ?string $chosenUrl = null): ?array
    {
        if ($chosenUrl !== null) {
            foreach ($cards as $card) {
                if (($card['url'] ?? null) !== null && $this->sameUrl($card['url'], $chosenUrl)) {
                    return $card;
                }
            }
        }

        return NameMatcher::best($cards, $name, fn (array $card) => $card['title']);
    }

    /**
     * What the preview shows: the winning card, or the closest ones when none is close enough.
     *
     * @param  array<int, array<string, mixed>>|null  $cards
     * @return array{state: 'matched'|'candidates'|'none'|'unavailable', best: ?array<string, mixed>, candidates: array<int, array<string, mixed>>}
     */
    public function describe(?array $cards, string $name): array
    {
        if ($cards === null) {
            return ['state' => 'unavailable', 'best' => null, 'candidates' => []];
        }

        $best = $this->pick($cards, $name);
        $others = NameMatcher::candidates($cards, $name, fn (array $card) => $card['title']);

        $scored = fn (array $card) => $this->summary($card, $name);

        return [
            'state' => $best ? 'matched' : ($others ? 'candidates' : 'none'),
            'best' => $best ? $scored($best) : null,
            'candidates' => array_values(array_map(
                $scored,
                array_filter($others, fn (array $card) => ! $best || $card['url'] !== $best['url']),
            )),
        ];
    }

    /**
     * @param  array<string, mixed>  $card
     * @return array{title: string, detail: ?string, rating: ?float, url: ?string, image: ?string, score: float}
     */
    private function summary(array $card, string $name): array
    {
        return [
            'title' => $card['title'],
            'detail' => $card['region'] ?? $card['style'] ?? null,
            'rating' => $card['rating'] ?? null,
            'url' => $card['url'] ?? null,
            'image' => $card['image'] ?? null,
            'score' => round(NameMatcher::score($name, $card['title']), 2),
        ];
    }

    /**
     * The same wine page and vintage: path and `year`, ignoring tracking params like `price_id`.
     */
    private function sameUrl(string $a, string $b): bool
    {
        $key = function (string $url): string {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return rtrim((string) parse_url($url, PHP_URL_PATH), '/') . '?' . ($query['year'] ?? '');
        };

        return $key($a) === $key($b);
    }
}
