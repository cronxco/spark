<?php

namespace App\Spotlight\Queries\Search;

use App\Integrations\PluginRegistry;
use App\Models\Block;
use Illuminate\Support\Str;
use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class BlockSearchQuery
{
    /**
     * Create Spotlight query for searching blocks.
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::asDefault(function (string $query) {
            if (blank($query) || strlen($query) < 3) {
                return collect();
            }

            return Block::with(['event'])
                ->where(function ($q) use ($query) {
                    $q->where('title', 'ilike', "%{$query}%")
                        ->orWhere('block_type', 'ilike', "%{$query}%");
                })
                ->latest('time')
                ->limit(5)
                ->get()
                ->map(function (Block $block) {
                    $blockTitle = $block->title ?? ucfirst(str_replace('_', ' ', $block->block_type ?? 'Block'));

                    // Build subtitle parts
                    $subtitleParts = [];
                    $blockIcon = 'squares-2x2';

                    // Get service from event
                    $service = $block->event?->service;

                    if ($service) {
                        $pluginClass = PluginRegistry::getPlugin($service);
                        $serviceName = $pluginClass
                            ? $pluginClass::getDisplayName()
                            : Str::headline($service);
                        $subtitleParts[] = $serviceName;

                        // Get block type icon from plugin
                        if ($pluginClass && $block->block_type) {
                            $blockTypes = $pluginClass::getBlockTypes();
                            if (isset($blockTypes[$block->block_type]['icon'])) {
                                $blockIcon = normalize_icon_for_spotlight($blockTypes[$block->block_type]['icon']);
                            }
                        }
                    }

                    // Add block type
                    if ($block->block_type) {
                        $subtitleParts[] = ucfirst(str_replace('_', ' ', $block->block_type));
                    }

                    // Get formatted value
                    if ($block->value !== null) {
                        $formattedValue = format_event_value_display(
                            $block->value / ($block->value_multiplier ?: 1),
                            $block->value_unit,
                            $service ?? 'unknown',
                            $block->block_type,
                            'block'
                        );
                        if ($formattedValue) {
                            $subtitleParts[] = $formattedValue;
                        }
                    }

                    // Add date with human readable format
                    if ($block->time) {
                        $dateFormat = $block->time->format('M j, Y');
                        $humanDate = $block->time->diffForHumans();
                        $subtitleParts[] = "{$dateFormat} ({$humanDate})";
                    }

                    $subtitle = implode(' • ', $subtitleParts);

                    // Boost priority for recent blocks
                    $priority = $block->time && $block->time->isAfter(now()->subWeek()) ? 1 : 2;

                    return SpotlightResult::make()
                        ->setTitle($blockTitle)
                        ->setSubtitle($subtitle)
                        ->setTypeahead('Block: ' . $blockTitle)
                        ->setIcon($blockIcon)
                        ->setGroup('blocks')
                        ->setPriority($priority)
                        ->setAction('jump_to', ['path' => route('blocks.show', $block)])
                        ->setTokens(['block' => $block]);
                });
        });
    }
}
