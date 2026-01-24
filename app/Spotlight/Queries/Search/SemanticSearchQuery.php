<?php

namespace App\Spotlight\Queries\Search;

use App\Integrations\PluginRegistry;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Services\EmbeddingService;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class SemanticSearchQuery
{
    /**
     * Create Spotlight query for semantic search across events and blocks.
     * Uses dedicated mode (~) to avoid running expensive AI queries on every keystroke.
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::forMode('semantic', function (string $query) {
            // Require at least 3 characters for semantic search
            if (blank($query) || strlen($query) < 3) {
                return collect();
            }

            // Check if OpenAI is configured
            if (empty(config('services.openai.api_key'))) {
                return collect();
            }

            try {
                $embeddingService = app(EmbeddingService::class);

                // Generate embedding for the query
                $embedding = $embeddingService->embed($query);

                // Get user's integration IDs for security
                $userIntegrationIds = auth()->user()->integrations()->pluck('id')->toArray();

                if (empty($userIntegrationIds)) {
                    return collect();
                }

                // Search events (limit to top 3 for Spotlight)
                // Use temporal weighting to slightly boost recent events
                $events = Event::semanticSearch($embedding, threshold: 0.8, limit: 3, temporalWeight: 0.01)
                    ->whereIn('integration_id', $userIntegrationIds)
                    ->with(['actor', 'target', 'integration'])
                    ->get();

                // Search blocks (limit to top 3 for Spotlight)
                // Use temporal weighting to slightly boost recent blocks
                $blocks = Block::semanticSearch($embedding, threshold: 0.8, limit: 3, temporalWeight: 0.01)
                    ->whereHas('event', function ($q) use ($userIntegrationIds) {
                        $q->whereIn('integration_id', $userIntegrationIds);
                    })
                    ->with(['event.integration'])
                    ->get();

                // Search objects (limit to top 3 for Spotlight)
                // Use temporal weighting to slightly boost recent objects
                $objects = EventObject::semanticSearch($embedding, threshold: 0.8, limit: 3, temporalWeight: 0.01)
                    ->where('user_id', auth()->id())
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
                    $subtitleParts[] = "{$similarity}% match";

                    $subtitle = implode(' • ', $subtitleParts);

                    $results->push(
                        SpotlightResult::make()
                            ->setTitle('🔍 '.format_action_title($event->action))
                            ->setSubtitle($subtitle)
                            ->setTypeahead('Semantic: '.$event->action.' at '.$event->time->format('M j, g:ia'))
                            ->setIcon('magnifying-glass')
                            ->setGroup('events')
                            ->setPriority(10) // Lower priority than exact matches
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
                    $subtitleParts[] = "{$similarity}% match";

                    $subtitle = implode(' • ', $subtitleParts);

                    // Get block title
                    $title = $block->title ?? ucfirst(str_replace('_', ' ', $block->block_type ?? 'Block'));

                    $results->push(
                        SpotlightResult::make()
                            ->setTitle('🔍 '.$title)
                            ->setSubtitle($subtitle)
                            ->setTypeahead('Semantic: '.$title)
                            ->setIcon('document-text')
                            ->setGroup('blocks')
                            ->setPriority(11) // Slightly lower priority than events
                            ->setAction('jump_to', ['path' => route('blocks.show', $block)])
                            ->setTokens(['block' => $block])
                    );
                }

                // Format object results
                foreach ($objects as $object) {
                    $similarity = round((1 - ($object->similarity ?? 0)) * 100);

                    // Build subtitle
                    $subtitleParts = [];

                    // Add concept and type
                    if ($object->concept) {
                        $subtitleParts[] = Str::headline($object->concept);
                    }
                    if ($object->type) {
                        $subtitleParts[] = Str::headline($object->type);
                    }

                    // Add date if available
                    if ($object->time) {
                        $subtitleParts[] = $object->time->format('M j, g:ia');
                    }

                    // Add similarity score
                    $subtitleParts[] = "{$similarity}% match";

                    $subtitle = implode(' • ', $subtitleParts);

                    $results->push(
                        SpotlightResult::make()
                            ->setTitle('🔍 '.($object->title ?? 'Untitled'))
                            ->setSubtitle($subtitle)
                            ->setTypeahead('Semantic: '.($object->title ?? 'Object'))
                            ->setIcon('cube')
                            ->setGroup('objects')
                            ->setPriority(12) // Slightly lower priority than blocks
                            ->setAction('jump_to', ['path' => route('objects.show', $object)])
                            ->setTokens(['object' => $object])
                    );
                }

                return $results;
            } catch (Exception $e) {
                // Silently fail - don't interrupt user experience
                // Log error for debugging
                Log::warning('Semantic search in Spotlight failed', [
                    'error' => $e->getMessage(),
                    'query' => $query,
                ]);

                return collect();
            }
        });
    }
}
