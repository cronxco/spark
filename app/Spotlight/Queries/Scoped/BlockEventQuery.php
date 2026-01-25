<?php

namespace App\Spotlight\Queries\Scoped;

use App\Integrations\PluginRegistry;
use App\Models\Block;
use Illuminate\Support\Str;
use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class BlockEventQuery
{
    /**
     * Create Spotlight query for navigating to block's parent event.
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

            $event = $block->event;

            // Get formatted value
            $formattedValue = format_event_value_display(
                $event->formatted_value,
                $event->value_unit,
                $event->service ?? 'unknown',
                $event->action
            );

            // Build subtitle parts
            $subtitleParts = [];
            $actionIcon = 'list-bullet';

            if ($event->service) {
                $pluginClass = PluginRegistry::getPlugin($event->service);
                $serviceName = $pluginClass
                    ? $pluginClass::getDisplayName()
                    : Str::headline($event->service);
                $subtitleParts[] = $serviceName;

                // Get action icon from plugin
                if ($pluginClass && $event->action) {
                    $actionTypes = $pluginClass::getActionTypes();
                    if (isset($actionTypes[$event->action]['icon'])) {
                        $actionIcon = normalize_icon_for_spotlight($actionTypes[$event->action]['icon']);
                    }
                }
            }

            // Add formatted value
            if ($formattedValue) {
                $subtitleParts[] = $formattedValue;
            }

            // Add date
            $dateFormat = $event->time->format('M j, g:ia');
            $humanDate = $event->time->diffForHumans();
            $subtitleParts[] = "{$dateFormat} ({$humanDate})";

            $subtitle = implode(' • ', $subtitleParts);

            return collect([
                SpotlightResult::make()
                    ->setTitle('Parent Event: ' . format_action_title($event->action))
                    ->setSubtitle($subtitle)
                    ->setIcon($actionIcon)
                    ->setGroup('events')
                    ->setPriority(1)
                    ->setAction('jump_to', ['path' => route('events.show', $event)])
                    ->setTokens(['event' => $event]),
            ]);
        });
    }
}
