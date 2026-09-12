<?php

namespace App\Support;

use App\Models\Block;
use App\Models\Event;
use App\Services\Flint\RoutineConfig;
use Illuminate\Support\Str;

/**
 * What sort of digest this is: the daily briefing, a news roundup, or a
 * reading list.
 *
 * Resolved once, here, so every surface agrees. The order matters:
 *
 * 1. `kind`, written at creation from the verified run token. Authoritative.
 * 2. `routine`, for digests written before `kind` existed.
 * 3. The title, then the block types — guesses, kept only for conversational
 *    digests that carry no run token and for the back catalogue.
 *
 * The title sniff was the original mechanism and worked only because the
 * skills happen to name their digests "News roundup — Friday" and "Reading
 * list — Friday". That coupled presentation to editorial wording across two
 * repositories with nothing enforcing it.
 */
class FlintDigestKind
{
    public const BRIEFING = 'briefing';

    public const NEWS_ROUNDUP = 'news_roundup';

    public const READING_LIST = 'reading_list';

    public const ALL = [self::BRIEFING, self::NEWS_ROUNDUP, self::READING_LIST];

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public static function for(Event $event, ?array $meta = null): string
    {
        $meta ??= $event->event_metadata ?? [];

        $declared = $meta['kind'] ?? null;
        if (is_string($declared) && in_array($declared, self::ALL, true)) {
            return $declared;
        }

        $fromRoutine = RoutineConfig::digestKind(
            is_string($meta['routine'] ?? null) ? $meta['routine'] : null
        );
        if ($fromRoutine !== null) {
            return $fromRoutine;
        }

        return self::guess($event, $meta);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private static function guess(Event $event, array $meta): string
    {
        $title = Str::lower((string) ($meta['title'] ?? ''));

        if (Str::contains($title, ['reading list', 'saved to read'])) {
            return self::READING_LIST;
        }

        if (Str::contains($title, ['news', 'roundup'])) {
            return self::NEWS_ROUNDUP;
        }

        $contentBlocks = $event->blocks->filter(
            fn (Block $block): bool => ! in_array(
                $block->block_type,
                ['flint_editorial_note', 'flint_user_question', 'flint_day_context'],
                true
            )
        );

        if ($contentBlocks->isNotEmpty()
            && $contentBlocks->every(fn (Block $block): bool => $block->block_type === 'flint_news')) {
            return self::NEWS_ROUNDUP;
        }

        if ($contentBlocks->isNotEmpty()
            && $contentBlocks->every(fn (Block $block): bool => in_array(
                $block->block_type,
                ['flint_reading_pick', 'flint_reading_drop'],
                true
            ))) {
            return self::READING_LIST;
        }

        return self::BRIEFING;
    }
}
