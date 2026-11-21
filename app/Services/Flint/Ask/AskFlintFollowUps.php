<?php

namespace App\Services\Flint\Ask;

use Illuminate\Support\Str;

/**
 * Suggested next questions that Flint writes after an answer.
 *
 * The agent ends an answer with `<follow-ups>` and up to two short questions,
 * one per line. The block is never shown as text: the stream holds it back,
 * and the transcript lifts it out into `follow_ups`.
 */
class AskFlintFollowUps
{
    public const OPEN = '<follow-ups>';

    public const CLOSE = '</follow-ups>';

    public const MAX = 2;

    /**
     * The answer without its follow-ups block, and the questions in it.
     *
     * @return array{text: string, follow_ups: array<int, string>}
     */
    public static function split(string $text): array
    {
        $start = strpos($text, self::OPEN);
        if ($start === false) {
            return ['text' => $text, 'follow_ups' => []];
        }

        $block = substr($text, $start + strlen(self::OPEN));
        $end = strpos($block, self::CLOSE);
        $block = $end === false ? $block : substr($block, 0, $end);

        $questions = collect(preg_split('/\R/', $block) ?: [])
            ->map(fn (string $line): string => trim(preg_replace('/^\s*(?:[-*•]|\d+[.)])\s*/u', '', $line) ?? ''))
            ->filter(fn (string $line): bool => $line !== '' && mb_strlen($line) <= 120)
            ->map(fn (string $line): string => Str::ucfirst($line))
            ->unique()
            ->take(self::MAX)
            ->values()
            ->all();

        return ['text' => rtrim(substr($text, 0, $start)), 'follow_ups' => $questions];
    }

    /**
     * How much of `$pending` can be sent now. Text from the start of the
     * follow-ups block onwards is never sent, and a tail that could be the
     * start of the opening tag waits for the next delta.
     */
    public static function sendable(string $pending): int
    {
        $start = strpos($pending, self::OPEN);
        if ($start !== false) {
            return $start;
        }

        $length = strlen($pending);
        $max = min($length, strlen(self::OPEN) - 1);

        for ($size = $max; $size > 0; $size--) {
            if (str_starts_with(self::OPEN, substr($pending, -$size))) {
                return $length - $size;
            }
        }

        return $length;
    }
}
