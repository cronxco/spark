<?php

namespace App\Spotlight\Queries\Scoped;

use App\Integrations\PluginRegistry;
use App\Models\Event;
use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class EventBlocksQuery
{
    /**
     * Create Spotlight query for listing all blocks from an event.
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::forToken('event', function (string $query, $eventToken) {
            $eventId = $eventToken->getParameter('id');
            if (! $eventId) {
                return collect();
            }

            $event = Event::with('blocks')->find($eventId);
            if (! $event || $event->blocks->isEmpty()) {
                return collect();
            }

            $service = $eventToken->getParameter('service');
            $pluginClass = $service ? PluginRegistry::getPlugin($service) : null;

            return $event->blocks
                ->filter(function ($block) use ($query) {
                    if (blank($query)) {
                        return true;
                    }
                    $searchText = strtolower($block->title.' '.$block->block_type);

                    return str_contains($searchText, strtolower($query));
                })
                ->take(10)
                ->map(function ($block) use ($pluginClass, $service) {
                    $blockTitle = $block->title ?? ucfirst(str_replace('_', ' ', $block->block_type ?? 'Block'));
                    $blockIcon = 'squares-2x2';

                    // Get icon from plugin
                    if ($pluginClass && $block->block_type) {
                        $blockTypes = $pluginClass::getBlockTypes();
                        if (isset($blockTypes[$block->block_type]['icon'])) {
                            $blockIcon = normalize_icon_for_spotlight($blockTypes[$block->block_type]['icon']);
                        }
                    }

                    $subtitleParts = [];
                    if ($block->block_type) {
                        $subtitleParts[] = ucfirst(str_replace('_', ' ', $block->block_type));
                    }

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

                    $subtitle = implode(' • ', $subtitleParts);

                    return SpotlightResult::make()
                        ->setTitle($blockTitle)
                        ->setSubtitle($subtitle)
                        ->setIcon($blockIcon)
                        ->setGroup('blocks')
                        ->setPriority(1)
                        ->setAction('jump_to', ['path' => route('blocks.show', $block)])
                        ->setTokens(['block' => $block]);
                });
        });
    }
}
