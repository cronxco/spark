<?php

namespace App\Support;

use App\Models\Block;
use App\Models\User;
use Carbon\CarbonImmutable;
use Ramsey\Uuid\Uuid;

/**
 * Whether a Flint question is still open, and when it stops being one.
 *
 * A question can become permanent dead weight when it is never answered:
 * never closed, and still counted in every unanswered badge.
 *
 * Retirement is that closure, and it is deliberately not a deletion. The block
 * stays in its digest, stays visible and stays answerable — a late answer is
 * still the best evidence available — it simply stops presenting as something
 * waiting on a reply. The sweep below is the only place the age rule lives;
 * every reader just checks the stamp it writes.
 */
class FlintQuestion
{
    public const BLOCK_TYPE = 'flint_user_question';

    /**
     * Days an unanswered question stays open. Matches the retirement rule in
     * spark-day-briefing-async, which announces the retirement once in its
     * editorial note.
     */
    public const RETIREMENT_DAYS = 7;

    public static function isQuestion(Block $block): bool
    {
        return $block->block_type === self::BLOCK_TYPE;
    }

    public static function isAnswered(Block $block): bool
    {
        return ! is_null(($block->metadata ?? [])['answer'] ?? null);
    }

    public static function status(Block $block): string
    {
        $metadata = $block->metadata ?? [];

        if (self::isAnswered($block)) {
            return 'answered';
        }

        if (($metadata['question_status'] ?? null) === 'skipped' || ! empty($metadata['skipped_at'])) {
            return 'skipped';
        }

        return self::isRetired($block) ? 'retired' : 'open';
    }

    /** @return array<int, array<string, mixed>> */
    public static function history(Block $block): array
    {
        $metadata = $block->metadata ?? [];
        $history = $metadata['action_history'] ?? [];

        if (is_array($history) && $history !== []) {
            return array_values(array_filter($history, 'is_array'));
        }

        if (! self::isAnswered($block)) {
            return [];
        }

        return [[
            'id' => (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'legacy-answer:' . $block->id),
            'client_mutation_id' => 'legacy-answer:' . $block->id,
            'action' => 'answer',
            'answer' => $metadata['answer'],
            'context' => $metadata['answer_note'] ?? null,
            'created_at' => $metadata['answered_at'] ?? $block->updated_at?->toIso8601String(),
            'legacy' => true,
        ]];
    }

    public static function retiredAt(Block $block): ?CarbonImmutable
    {
        $stamp = ($block->metadata ?? [])['retired_at'] ?? null;

        return is_string($stamp) && $stamp !== '' ? CarbonImmutable::parse($stamp) : null;
    }

    /**
     * Retired, and still unanswered. An answer that arrives after retirement
     * reopens nothing and needs to reopen nothing — it is simply an answered
     * question, which no badge counts either.
     */
    public static function isRetired(Block $block): bool
    {
        return ! self::isAnswered($block) && self::retiredAt($block) !== null;
    }

    /**
     * Whether this block is a question still waiting on a reply — the one
     * predicate every unanswered count should use.
     */
    public static function isOpen(Block $block): bool
    {
        return self::isQuestion($block)
            && self::status($block) === 'open';
    }

    /**
     * Stamp every question of this user's that has gone unanswered past the
     * retirement horizon, and report how many were closed.
     *
     * Runs once per digest write rather than on a schedule of its own: a
     * digest is the only thing that asks a question, so it is the natural
     * moment to tidy up the ones that were never answered.
     */
    public static function retireStale(User $user, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $cutoff = $now->subDays(self::RETIREMENT_DAYS);

        // Narrowed in SQL as well as in PHP below, so the sweep stays the size
        // of what it actually has to change rather than growing with every
        // question ever asked.
        $stale = Block::query()
            ->where('block_type', self::BLOCK_TYPE)
            ->where('time', '<', $cutoff)
            ->whereNull('metadata->answer')
            ->whereNull('metadata->retired_at')
            ->whereHas('event.integration', fn ($query) => $query->where('user_id', $user->id))
            ->get();

        $retired = 0;

        foreach ($stale as $block) {
            if (self::isAnswered($block) || self::retiredAt($block) !== null) {
                continue;
            }

            $block->metadata = array_merge($block->metadata ?? [], [
                'retired_at' => $now->toIso8601String(),
                'question_status' => 'retired',
            ]);
            $block->save();
            $block->event?->touch();
            $retired++;
        }

        return $retired;
    }
}
