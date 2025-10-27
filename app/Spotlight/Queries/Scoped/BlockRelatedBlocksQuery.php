<?php

namespace App\Spotlight\Queries\Scoped;

use App\Integrations\PluginRegistry;
use App\Models\Block;
use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class BlockRelatedBlocksQuery
{
    /**
     * Create Spotlight query for listing other blocks from the same event.
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::forToken('block', function (string $query, $blockToken) {
            $blockId = $blockToken->getParameter('id');
            if (! $blockId) {
                return collect();
            }

            $block = Block::with('event')->find($blockId);
            if (! $block || ! $block->event) {
                return collect();
            }

            // Get all other blocks from same event
            $relatedBlocks = Block::where('event_id', $block->event_id)
                ->where('id', '!=', $blockId)
                ->when(! blank($query), function ($q) use ($query) {
                    $q->where(function ($q2) use ($query) {
                        $q2->where('title', 'ilike', "%{$query}%")
                            ->orWhere('block_type', 'ilike', "%{$query}%");
                    });
                })
                ->limit(10)
                ->get();

            if ($relatedBlocks->isEmpty()) {
                return collect();
            }

            $service = $block->event->service;
            $pluginClass = $service ? PluginRegistry::getPlugin($service) : null;

            return $relatedBlocks->map(function ($relatedBlock) use ($pluginClass, $service) {
                $blockTitle = $relatedBlock->title ?? ucfirst(str_replace('_', ' ', $relatedBlock->block_type ?? 'Block'));
                $blockIcon = 'squares-2x2';

                // Get icon from plugin
                if ($pluginClass && $relatedBlock->block_type) {
                    $blockTypes = $pluginClass::getBlockTypes();
                    if (isset($blockTypes[$relatedBlock->block_type]['icon'])) {
                        $blockIcon = normalize_icon_for_spotlight($blockTypes[$relatedBlock->block_type]['icon']);
                    }
                }

                $subtitleParts = [];
                if ($relatedBlock->block_type) {
                    $subtitleParts[] = ucfirst(str_replace('_', ' ', $relatedBlock->block_type));
                }

                if ($relatedBlock->value !== null) {
                    $formattedValue = format_event_value_display(
                        $relatedBlock->value / ($relatedBlock->value_multiplier ?: 1),
                        $relatedBlock->value_unit,
                        $service ?? 'unknown',
                        $relatedBlock->block_type,
                        'block'
                    );
                    if ($formattedValue) {
                        $subtitleParts[] = $formattedValue;
                    }
                }

                $subtitleParts[] = 'Related Block';

                $subtitle = implode(' • ', $subtitleParts);

                return SpotlightResult::make()
                    ->setTitle($blockTitle)
                    ->setSubtitle($subtitle)
                    ->setIcon($blockIcon)
                    ->setGroup('blocks')
                    ->setPriority(1)
                    ->setAction('jump_to', ['path' => route('blocks.show', $relatedBlock)])
                    ->setTokens(['block' => $relatedBlock]);
            });
        });
    }
}
