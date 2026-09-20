<?php

namespace App\Support;

use App\Services\FlintTopicService;

/**
 * Derives a topic's `watching_for` from its prose `content` when the writer
 * hasn't published one explicitly yet (MR-10).
 *
 * By convention `content` ends with the sentence naming what would move the
 * thread on — this is the client's former sentence-splitting heuristic, moved
 * server-side. It's a fallback: {@see FlintTopicService} accepts
 * an explicit `watching_for` field, written by the same routine that writes
 * `content`, and that always wins over this guess.
 */
class FlintTopicWatchingFor
{
    /**
     * A closing sentence shorter than this rarely carries anything on its
     * own ("More soon." / "TBD.") — reach back one more sentence instead.
     */
    private const MIN_LENGTH = 20;

    public static function extract(?string $content): ?string
    {
        if ($content === null || trim($content) === '') {
            return null;
        }

        $sentences = collect(preg_split('/(?<=[.!?])\s+/u', trim($content)) ?: [])
            ->map(fn (string $sentence) => trim($sentence))
            ->filter(fn (string $sentence) => $sentence !== '')
            ->values();

        if ($sentences->isEmpty()) {
            return null;
        }

        $last = $sentences->last();

        if (mb_strlen($last) < self::MIN_LENGTH && $sentences->count() > 1) {
            return $sentences->slice(-2)->implode(' ');
        }

        return $last;
    }
}
