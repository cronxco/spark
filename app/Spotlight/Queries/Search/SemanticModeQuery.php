<?php

namespace App\Spotlight\Queries\Search;

use App\Integrations\PluginRegistry;
use App\Models\Block;
use App\Models\Event;
use App\Services\EmbeddingService;
use Illuminate\Support\Str;
use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class SemanticModeQuery
{
    /**
     * Create Spotlight query for dedicated semantic search mode (~).
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::asMode('semantic', function (string $query) {
            // Require at least 2 characters in semantic mode
            if (blank($query) || strlen($query) < 2) {
                return collect();
            }

            // Check if OpenAI is configured
            if (empty(config('services.openai.api_key'))) {
                return collect([
                    SpotlightResult::make()
                        ->setTitle('OpenAI not configured')
                        ->setSubtitle('Set OPENAI_API_KEY in .env to use semantic search')
                        ->setIcon('exclamation-triangle')
                        ->setGroup('commands')
                        ->setPriority(1),
                ]);
            }

            try {
                $embeddingService = app(EmbeddingService::class);

                // Generate embedding for the query
                $embedding = $embeddingService->embed($query);

                // Get user's integration IDs for security
                $userIntegrationIds = auth()->user()->integrations()->pluck('id')->toArray();

                if (empty($userIntegrationIds)) {
                    return collect([
                        SpotlightResult::make()
                            ->setTitle('No integrations found')
                            ->setSubtitle('Add integrations to search your data')
                            ->setIcon('information-circle')
                            ->setGroup('commands')
                            ->setPriority(1)
                            ->setAction('jump_to', ['path' => route('integrations.index')]),
                    ]);
                }

                // In semantic mode, show more results and use looser threshold
                // Search events (limit to top 10)
                $events = Event::semanticSearch($embedding, threshold: 1.2, limit: 10, temporalWeight: 0.015)
                    ->whereIn('integration_id', $userIntegrationIds)
                    ->with(['actor', 'target', 'integration'])
                    ->get();

                // Search blocks (limit to top 10)
                $blocks = Block::semanticSearch($embedding, threshold: 1.2, limit: 10, temporalWeight: 0.015)
                    ->whereHas('event', function ($q) use ($userIntegrationIds) {
                        $q->whereIn('integration_id', $userIntegrationIds);
                    })
                    ->with(['event.integration'])
                    ->get();

                // Combine results
                $results = collect();

                // Format event results
                foreach ($events as $event) {
                    $similarity = round((1 - ($event->similarity ?? 0)) * 100);

                    // Get formatted value
                    $formattedValue = format_event_value_display(
                        $event->formatted_value,
                        $event->value_unit,
                        $event->service ?? 'unknown',
                        $event->action
                    );

                    // Build subtitle
                    $subtitleParts = [];

                    // Add service name
                    if ($event->service) {
                        $pluginClass = PluginRegistry::getPlugin($event->service);
                        $serviceName = $pluginClass
                            ? $pluginClass::getDisplayName()
                            : Str::headline($event->service);
                        $subtitleParts[] = $serviceName;
                    }

                    // Add formatted value
                    if ($formattedValue) {
                        $subtitleParts[] = $formattedValue;
                    }

                    // Add date
                    $subtitleParts[] = $event->time->format('M j, g:ia');

                    // Add similarity score
                    $subtitleParts[] = "✨ {$similarity}% match";

                    // Show days ago if temporal weighting applied
                    if (isset($event->days_ago)) {
                        $daysAgo = round($event->days_ago);
                        if ($daysAgo === 0) {
                            $subtitleParts[] = '🔥 Today';
                        } elseif ($daysAgo === 1) {
                            $subtitleParts[] = '⏰ Yesterday';
                        } elseif ($daysAgo < 7) {
                            $subtitleParts[] = "⏰ {$daysAgo}d ago";
                        }
                    }

                    $subtitle = implode(' • ', $subtitleParts);

                    // Get action icon
                    $actionIcon = 'sparkles';
                    if ($event->service && $event->action) {
                        $pluginClass = PluginRegistry::getPlugin($event->service);
                        if ($pluginClass) {
                            $actionTypes = $pluginClass::getActionTypes();
                            if (isset($actionTypes[$event->action]['icon'])) {
                                $actionIcon = normalize_icon_for_spotlight($actionTypes[$event->action]['icon']);
                            }
                        }
                    }

                    $results->push(
                        SpotlightResult::make()
                            ->setTitle(format_action_title($event->action))
                            ->setSubtitle($subtitle)
                            ->setTypeahead('Event: ' . $event->action . ' at ' . $event->time->format('M j, g:ia'))
                            ->setIcon($actionIcon)
                            ->setGroup('events')
                            ->setPriority(1)
                            ->setAction('jump_to', ['path' => route('events.show', $event)])
                            ->setTokens(['event' => $event])
                    );
                }

                // Format block results
                foreach ($blocks as $block) {
                    $similarity = round((1 - ($block->similarity ?? 0)) * 100);

                    // Build subtitle
                    $subtitleParts = [];

                    // Add service name from event
                    if ($block->event && $block->event->service) {
                        $pluginClass = PluginRegistry::getPlugin($block->event->service);
                        $serviceName = $pluginClass
                            ? $pluginClass::getDisplayName()
                            : Str::headline($block->event->service);
                        $subtitleParts[] = $serviceName;
                    }

                    // Add block type if available
                    if ($block->block_type) {
                        $subtitleParts[] = Str::headline($block->block_type);
                    }

                    // Add date if available
                    if ($block->time) {
                        $subtitleParts[] = $block->time->format('M j, g:ia');
                    }

                    // Add similarity score
                    $subtitleParts[] = "✨ {$similarity}% match";

                    // Show days ago if temporal weighting applied
                    if (isset($block->days_ago) && $block->time) {
                        $daysAgo = round($block->days_ago);
                        if ($daysAgo === 0) {
                            $subtitleParts[] = '🔥 Today';
                        } elseif ($daysAgo === 1) {
                            $subtitleParts[] = '⏰ Yesterday';
                        } elseif ($daysAgo < 7) {
                            $subtitleParts[] = "⏰ {$daysAgo}d ago";
                        }
                    }

                    $subtitle = implode(' • ', $subtitleParts);

                    // Get block title
                    $title = $block->title ?? ucfirst(str_replace('_', ' ', $block->block_type ?? 'Block'));

                    $results->push(
                        SpotlightResult::make()
                            ->setTitle($title)
                            ->setSubtitle($subtitle)
                            ->setTypeahead('Block: ' . $title)
                            ->setIcon('document-text')
                            ->setGroup('blocks')
                            ->setPriority(2)
                            ->setAction('jump_to', ['path' => route('blocks.show', $block)])
                            ->setTokens(['block' => $block])
                    );
                }

                // If no results, show helpful message
                if ($results->isEmpty()) {
                    return collect([
                        SpotlightResult::make()
                            ->setTitle('No semantic matches found')
                            ->setSubtitle('Try a different query or adjust your search terms')
                            ->setIcon('magnifying-glass')
                            ->setGroup('commands')
                            ->setPriority(1),
                    ]);
                }

                return $results;
            } catch (\Exception $e) {
                // Show error in semantic mode (unlike default mode which fails silently)
                \Log::error('Semantic search mode failed', [
                    'error' => $e->getMessage(),
                    'query' => $query,
                ]);

                return collect([
                    SpotlightResult::make()
                        ->setTitle('Semantic search error')
                        ->setSubtitle('Check logs for details: ' . Str::limit($e->getMessage(), 50))
                        ->setIcon('exclamation-triangle')
                        ->setGroup('commands')
                        ->setPriority(1),
                ]);
            }
        });
    }
}
