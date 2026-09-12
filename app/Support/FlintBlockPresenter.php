<?php

namespace App\Support;

use App\Models\Block;
use Illuminate\Support\Collection;

/**
 * The one place a Flint digest block is turned into an API payload.
 *
 * REST and MCP each grew their own copy of this and then drifted: REST learned
 * to unwrap `flint_day_context` while MCP did not, so a day-context block came
 * back through MCP as `"content": null` with the calendar and weather invisible
 * — including to the Flint routines that read their own digests back. Neither
 * surface returned `referenced_event_ids` at all, so a news story's citations
 * were write-only.
 *
 * Reference linkification is the one deliberate difference and stays a caller
 * option: REST rewrites referenced titles into deep links for the mobile
 * renderer, while MCP hands the raw markdown to a model that should see the
 * text as written.
 */
class FlintBlockPresenter
{
    /**
     * @param  Collection<int, Block>  $blocks
     * @param  array<int, string>|Collection<int, string>|null  $integrationIds  the digest owner's integrations, so a
     *                                                                           citation cannot resolve someone else's event
     * @return array<int, array<string, mixed>>
     */
    public static function collection(
        Collection $blocks,
        bool $linkify = false,
        mixed $integrationIds = null,
    ): array {
        $referenceLookup = $linkify
            ? collect(EntityReferenceResolver::resolveEvents(
                self::referencedIds($blocks),
                $integrationIds,
            ))->keyBy('id')
            : collect();

        return $blocks
            ->map(fn (Block $block): array => self::present($block, $linkify, $referenceLookup))
            ->values()
            ->all();
    }

    /**
     * Every event id cited by any block, deduplicated — so references resolve
     * in one query rather than one per block.
     *
     * @param  Collection<int, Block>  $blocks
     * @return array<int, string>
     */
    public static function referencedIds(Collection $blocks): array
    {
        return $blocks
            ->flatMap(fn (Block $block): array => $block->metadata['referenced_event_ids'] ?? [])
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $referenceLookup
     * @return array<string, mixed>
     */
    private static function present(Block $block, bool $linkify, Collection $referenceLookup): array
    {
        $base = [
            'id' => $block->id,
            'block_type' => $block->block_type,
            'title' => $block->title,
            'time' => $block->time?->toIso8601String(),
        ];

        $meta = $block->metadata ?? [];

        if ($block->block_type === 'flint_user_question') {
            return $base + [
                'question' => $meta['question'] ?? null,
                'topic' => $meta['topic'] ?? null,
                'priority' => $meta['priority'] ?? null,
                'answer_options' => $meta['answer_options'] ?? null,
                'answer' => $meta['answer'] ?? null,
                'answer_note' => $meta['answer_note'] ?? null,
                'answered_at' => $meta['answered_at'] ?? null,
                'answered' => ! is_null($meta['answer'] ?? null),
            ];
        }

        if ($block->block_type === 'flint_day_context') {
            // Structured JSON, not markdown prose — skip linkify() entirely so
            // an incidental `[[event:...]]`-shaped substring in a title can't
            // get rewritten and corrupt the payload.
            $base['day_context'] = $meta['day_context'] ?? null;

            return $base;
        }

        $referencedIds = $meta['referenced_event_ids'] ?? [];
        $references = collect($referencedIds)
            ->map(fn ($id) => $referenceLookup->get($id))
            ->filter()
            ->values()
            ->all();

        $base['content'] = $linkify
            ? EntityReferenceResolver::linkify($block->getContent(), $references)
            : $block->getContent();

        if (! empty($references)) {
            $base['references'] = $references;
        }

        // Citations are useless if only the writer can see them. MCP has no
        // resolved reference objects, so it gets the raw ids.
        if (! empty($referencedIds)) {
            $base['referenced_event_ids'] = array_values($referencedIds);
        }

        // A reading pick's link and length live on the block's own columns.
        // Without these the client is back to recovering them from prose.
        if ($block->url) {
            $base['url'] = $block->url;
        }

        if ($block->value !== null && $block->value_unit === 'minutes') {
            $base['minutes'] = (int) $block->formatted_value;
        }

        return $base;
    }
}
