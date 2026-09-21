<?php

namespace App\Support;

/**
 * Derives a digest's lede from its prose `summary` when the generating skill
 * hasn't published one explicitly yet.
 *
 * This is the same heuristic the client used to have to run on-device: drop a
 * short greeting, drop an all-caps heading, strip a leading em dash and
 * Markdown emphasis, then keep to a sentence boundary. It's a fallback, not
 * the contract — {@see FlintDigestService::create()} accepts an explicit
 * `opener` field, and a caller that sends one always wins over this guess.
 */
class FlintDigestOpener
{
    private const MAX_LENGTH = 240;

    public static function extract(?string $summary): ?string
    {
        if ($summary === null || trim($summary) === '') {
            return null;
        }

        $paragraphs = collect(preg_split('/\n{2,}/', trim($summary)) ?: [])
            ->map(fn (string $paragraph) => trim($paragraph))
            ->filter(fn (string $paragraph) => $paragraph !== '')
            ->values();

        if ($paragraphs->isEmpty()) {
            return null;
        }

        foreach ($paragraphs as $paragraph) {
            if (mb_strlen($paragraph) < 40) {
                continue;
            }

            if (self::isAllCapsHeading($paragraph)) {
                continue;
            }

            return self::clean($paragraph);
        }

        // Nothing survived the filters (e.g. every paragraph is a short
        // greeting) — the first paragraph is still the best guess available.
        return self::clean($paragraphs->first());
    }

    private static function isAllCapsHeading(string $paragraph): bool
    {
        $letters = preg_replace('/[^A-Za-z]/', '', $paragraph) ?? '';

        return $letters !== '' && $letters === strtoupper($letters);
    }

    private static function clean(string $paragraph): string
    {
        $text = preg_replace('/^—\s*/u', '', $paragraph) ?? $paragraph;
        $text = preg_replace('/[*_`]+/', '', $text) ?? $text;

        return self::truncateAtSentence(trim($text));
    }

    private static function truncateAtSentence(string $text): string
    {
        if (mb_strlen($text) <= self::MAX_LENGTH) {
            return $text;
        }

        $slice = mb_substr($text, 0, self::MAX_LENGTH);
        $boundary = max(
            mb_strrpos($slice, '. ') ?: -1,
            mb_strrpos($slice, '! ') ?: -1,
            mb_strrpos($slice, '? ') ?: -1,
        );

        if ($boundary > 40) {
            return mb_substr($slice, 0, $boundary + 1);
        }

        return rtrim($slice).'…';
    }
}
