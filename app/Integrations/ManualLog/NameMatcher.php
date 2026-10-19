<?php

namespace App\Integrations\ManualLog;

/**
 * Scores how well a search result's name covers what the user typed.
 *
 * Wine and beer search pages are fuzzy and often lead with an unrelated
 * result, so enrichment only trusts a result that contains most of the
 * query's words.
 */
class NameMatcher
{
    /** Share of the query's words a result must contain to count as a match. */
    public const MATCH_THRESHOLD = 0.75;

    /** Share of the query's words a result must contain to be offered as a candidate. */
    public const CANDIDATE_THRESHOLD = 0.5;

    /**
     * Share of the query's words found in the candidate name (0-1), ignoring
     * case, accents and punctuation.
     */
    public static function score(string $query, string $candidate): float
    {
        $queryWords = self::words($query);
        if ($queryWords === []) {
            return 0.0;
        }

        return count(array_intersect($queryWords, self::words($candidate))) / count($queryWords);
    }

    /**
     * @template T of array
     *
     * @param  array<int, T>  $results
     * @param  callable(T): string  $name
     * @return T|null
     */
    public static function best(array $results, string $query, callable $name): ?array
    {
        $best = null;
        $bestScore = 0.0;

        foreach ($results as $result) {
            $score = self::score($query, $name($result));
            if ($score > $bestScore) {
                $best = $result;
                $bestScore = $score;
            }
        }

        return $bestScore >= self::MATCH_THRESHOLD ? $best : null;
    }

    /**
     * @template T of array
     *
     * @param  array<int, T>  $results
     * @param  callable(T): string  $name
     * @return array<int, T>
     */
    public static function candidates(array $results, string $query, callable $name, int $limit = 5): array
    {
        $scored = [];
        foreach ($results as $result) {
            $score = self::score($query, $name($result));
            if ($score >= self::CANDIDATE_THRESHOLD) {
                $scored[] = [$score, $result];
            }
        }

        usort($scored, fn (array $a, array $b) => $b[0] <=> $a[0]);

        return array_map(fn (array $pair) => $pair[1], array_slice($scored, 0, $limit));
    }

    /**
     * @return array<int, string>
     */
    public static function words(string $text): array
    {
        $text = mb_strtolower($text);
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $text = preg_replace('/[^a-z0-9]+/', ' ', $transliterated !== false ? $transliterated : $text) ?? '';

        return array_values(array_unique(array_filter(explode(' ', trim($text)))));
    }
}
