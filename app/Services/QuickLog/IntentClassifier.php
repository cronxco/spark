<?php

namespace App\Services\QuickLog;

use App\Services\ManualLog\PersonMatcher;

/**
 * Decides what a line typed into the quick log is for, without writing anything.
 *
 * Only unambiguous signals are decided here: a `/` or `?` prefix forces command or search, and `@flint` always means a
 * note for Flint. The fuzzy log-versus-the-rest call is made on device (PCC) and sent as a hint; the server applies the
 * confidence threshold so "ask before saving" is enforced in one tunable place.
 */
class IntentClassifier
{
    public const FLINT_HANDLE = 'flint';

    public function __construct(private PersonMatcher $people) {}

    /**
     * @param  array{intent: string, confidence: float|int}|null  $hint
     * @return array{intent: string, confidence: float, needs_choice: bool, reason: string, candidates: array<int, string>, text: string, people: array<int, mixed>}
     */
    public function classify(string $userId, string $raw, ?array $hint = null): array
    {
        $text = trim($raw);
        $mentions = $this->mentions($text);
        $hasFlint = in_array(self::FLINT_HANDLE, $mentions, true);
        $people = $this->people->check($userId, array_values(array_filter($mentions, fn (string $name) => ! PersonMatcher::isReserved($name))));

        [$intent, $confidence, $reason, $body] = $this->decide($text, $hasFlint, $hint);

        $unsure = $intent === QuickLogIntent::Unsure;

        return [
            'intent' => $intent->value,
            'confidence' => $confidence,
            'needs_choice' => $unsure,
            'reason' => $reason,
            'candidates' => $unsure ? $this->candidates($hint) : [],
            'text' => $body,
            'people' => $people,
        ];
    }

    /** Lower-cased @handles in the text, without the @. */
    public function mentions(string $text): array
    {
        preg_match_all('/(?<![\w@])@([\p{L}\p{N}][\p{L}\p{N}._\'-]*)/u', $text, $matches);

        return array_values(array_unique(array_map(
            fn (string $handle) => mb_strtolower(rtrim($handle, '.\'-_')),
            $matches[1]
        )));
    }

    public function hasFlintHandle(string $text): bool
    {
        return in_array(self::FLINT_HANDLE, $this->mentions($text), true);
    }

    /** The note body for a Flint note: the text without the @flint handle. */
    public function flintBody(string $text): string
    {
        $stripped = preg_replace('/(?<![\w@])@flint\b[\s,:;-]*/iu', '', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $stripped) ?? $stripped);
    }

    /** @return array{0: QuickLogIntent, 1: float, 2: string, 3: string} */
    private function decide(string $text, bool $hasFlint, ?array $hint): array
    {
        if ($text === '') {
            return [QuickLogIntent::Unsure, 0.0, 'empty', $text];
        }

        if ($hasFlint) {
            $body = $this->flintBody($text);

            return $body === ''
                ? [QuickLogIntent::Unsure, 0.0, 'empty_flint_note', $text]
                : [QuickLogIntent::FlintNote, 1.0, 'flint_handle', $body];
        }

        $first = mb_substr($text, 0, 1);
        if ($first === '/' || $first === '?') {
            $body = trim(mb_substr($text, 1));

            return $body === ''
                ? [QuickLogIntent::Unsure, 0.0, 'empty_prefixed', $text]
                : [$first === '/' ? QuickLogIntent::Command : QuickLogIntent::Search, 1.0, 'prefix', $body];
        }

        $hinted = $hint ? QuickLogIntent::tryFrom($hint['intent']) : null;
        $confidence = $hint ? (float) $hint['confidence'] : 0.0;

        // A note for Flint is never inferred: it needs the explicit @flint handle.
        if ($hinted === null || $hinted === QuickLogIntent::Unsure || $hinted === QuickLogIntent::FlintNote) {
            return [QuickLogIntent::Unsure, $confidence, $hint ? 'low_confidence' : 'no_hint', $text];
        }

        if ($confidence < (float) config('ios.quick_log.confidence_threshold', 0.7)) {
            return [QuickLogIntent::Unsure, $confidence, 'low_confidence', $text];
        }

        return [$hinted, $confidence, 'client_hint', $text];
    }

    /** @return array<int, string> Intents to offer, the client's guess first. */
    private function candidates(?array $hint): array
    {
        $all = [QuickLogIntent::Log->value, QuickLogIntent::Command->value, QuickLogIntent::Search->value];
        $guess = $hint['intent'] ?? null;

        return in_array($guess, $all, true) ? [$guess, ...array_values(array_diff($all, [$guess]))] : $all;
    }
}
