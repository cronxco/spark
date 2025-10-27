<?php

namespace App\Spotlight\Queries\Scoped;

use App\Integrations\PluginRegistry;
use App\Models\Block;
use App\Models\Integration;
use Illuminate\Support\Str;
use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class IntegrationBlocksQuery
{
    /**
     * Create Spotlight query for listing blocks created by an integration.
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::forToken('integration', function (string $query, $integrationToken) {
            $integrationId = $integrationToken->getParameter('id');
            if (! $integrationId) {
                return collect();
            }

            $integration = Integration::find($integrationId);
            if (! $integration) {
                return collect();
            }

            // Get blocks from events created by this integration
            $blocks = Block::with('event')
                ->whereHas('event', function ($q) use ($integrationId) {
                    $q->where('integration_id', $integrationId);
                })
                ->when(! blank($query), function ($q) use ($query) {
                    $q->where(function ($subQ) use ($query) {
                        $subQ->where('title', 'ilike', "%{$query}%")
                            ->orWhere('block_type', 'ilike', "%{$query}%");
                    });
                })
                ->latest('block_time')
                ->limit(10)
                ->get();

            if ($blocks->isEmpty()) {
                return collect();
            }

            return $blocks->map(function ($block) use ($integration) {
                $blockTitle = $block->title ?? ucfirst(str_replace('_', ' ', $block->block_type ?? 'Block'));

                // Build subtitle
                $subtitleParts = [];

                // Add block type
                if ($block->block_type) {
                    $subtitleParts[] = Str::headline($block->block_type);
                }

                // Add date
                $dateFormat = $block->block_time->format('M j, Y');
                $humanDate = $block->block_time->diffForHumans();
                $subtitleParts[] = "{$dateFormat} ({$humanDate})";

                $subtitle = implode(' • ', $subtitleParts);

                // Get icon from plugin
                $icon = 'rectangle-stack';
                if ($integration->service) {
                    $pluginClass = PluginRegistry::getPlugin($integration->service);
                    if ($pluginClass && $block->block_type) {
                        $blockTypes = $pluginClass::getBlockTypes();
                        if (isset($blockTypes[$block->block_type]['icon'])) {
                            $icon = normalize_icon_for_spotlight($blockTypes[$block->block_type]['icon']);
                        }
                    }
                }

                // Boost priority for recent blocks
                $priority = $block->block_time->isAfter(now()->subWeek()) ? 1 : 2;

                return SpotlightResult::make()
                    ->setTitle($blockTitle)
                    ->setSubtitle($subtitle)
                    ->setIcon($icon)
                    ->setGroup('blocks')
                    ->setPriority($priority)
                    ->setAction('jump_to', ['path' => route('blocks.show', $block)])
                    ->setTokens(['block' => $block]);
            });
        });
    }
}
