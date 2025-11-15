<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Block;
use App\Models\Event;
use App\Services\EmbeddingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SearchApiController extends Controller
{
    public function __construct(
        private EmbeddingService $embeddingService
    ) {}

    /**
     * Perform semantic search on events
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function searchEvents(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'query' => 'required|string|min:1|max:500',
            'integration_id' => 'nullable|uuid|exists:integrations,id',
            'service' => 'nullable|string',
            'domain' => 'nullable|string',
            'action' => 'nullable|string',
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date',
            'threshold' => 'nullable|numeric|min:0|max:2',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'details' => $validator->errors(),
            ], 422);
        }

        $query = $request->input('query');
        $threshold = $request->input('threshold', 1.0);
        $limit = $request->input('limit', 20);

        // Generate embedding for the search query
        try {
            $embedding = $this->embeddingService->embed($query);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to generate embedding for query',
                'message' => $e->getMessage(),
            ], 500);
        }

        // Build filters array
        $filters = array_filter([
            'integration_id' => $request->input('integration_id'),
            'service' => $request->input('service'),
            'domain' => $request->input('domain'),
            'action' => $request->input('action'),
            'from_date' => $request->input('from_date'),
            'to_date' => $request->input('to_date'),
        ]);

        // Ensure user can only search their own integrations
        $userIntegrationIds = $request->user()->integrations()->pluck('id')->toArray();

        // Perform hybrid search
        $events = Event::hybridSearch($embedding, $filters, $threshold, $limit)
            ->whereIn('integration_id', $userIntegrationIds)
            ->with(['integration', 'actor', 'target', 'blocks'])
            ->get();

        return response()->json([
            'data' => $events->map(function ($event) {
                return [
                    'id' => $event->id,
                    'service' => $event->service,
                    'domain' => $event->domain,
                    'action' => $event->action,
                    'value' => $event->value,
                    'value_multiplier' => $event->value_multiplier,
                    'value_unit' => $event->value_unit,
                    'time' => $event->time,
                    'similarity' => round(1 - ($event->similarity ?? 0), 4), // Convert distance to similarity score
                    'integration' => $event->integration ? [
                        'id' => $event->integration->id,
                        'service' => $event->integration->service,
                        'name' => $event->integration->name,
                    ] : null,
                    'actor' => $event->actor ? [
                        'id' => $event->actor->id,
                        'title' => $event->actor->title,
                        'type' => $event->actor->type,
                    ] : null,
                    'target' => $event->target ? [
                        'id' => $event->target->id,
                        'title' => $event->target->title,
                        'type' => $event->target->type,
                    ] : null,
                    'blocks_count' => $event->blocks->count(),
                ];
            }),
            'meta' => [
                'query' => $query,
                'threshold' => $threshold,
                'limit' => $limit,
                'count' => $events->count(),
            ],
        ]);
    }

    /**
     * Perform semantic search on blocks
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function searchBlocks(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'query' => 'required|string|min:1|max:500',
            'event_id' => 'nullable|uuid|exists:events,id',
            'block_type' => 'nullable|string',
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date',
            'threshold' => 'nullable|numeric|min:0|max:2',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'details' => $validator->errors(),
            ], 422);
        }

        $query = $request->input('query');
        $threshold = $request->input('threshold', 1.0);
        $limit = $request->input('limit', 20);

        // Generate embedding for the search query
        try {
            $embedding = $this->embeddingService->embed($query);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to generate embedding for query',
                'message' => $e->getMessage(),
            ], 500);
        }

        // Build filters array
        $filters = array_filter([
            'event_id' => $request->input('event_id'),
            'block_type' => $request->input('block_type'),
            'from_date' => $request->input('from_date'),
            'to_date' => $request->input('to_date'),
        ]);

        // Ensure user can only search blocks from their own events
        $userIntegrationIds = $request->user()->integrations()->pluck('id')->toArray();

        // Perform hybrid search
        $blocks = Block::hybridSearch($embedding, $filters, $threshold, $limit)
            ->whereHas('event', function ($query) use ($userIntegrationIds) {
                $query->whereIn('integration_id', $userIntegrationIds);
            })
            ->with(['event.integration'])
            ->get();

        return response()->json([
            'data' => $blocks->map(function ($block) {
                return [
                    'id' => $block->id,
                    'title' => $block->title,
                    'content' => $block->getContent(),
                    'url' => $block->url,
                    'media_url' => $block->media_url,
                    'value' => $block->value,
                    'value_multiplier' => $block->value_multiplier,
                    'value_unit' => $block->value_unit,
                    'block_type' => $block->block_type,
                    'time' => $block->time,
                    'similarity' => round(1 - ($block->similarity ?? 0), 4), // Convert distance to similarity score
                    'event' => $block->event ? [
                        'id' => $block->event->id,
                        'service' => $block->event->service,
                        'domain' => $block->event->domain,
                        'action' => $block->event->action,
                        'integration' => $block->event->integration ? [
                            'id' => $block->event->integration->id,
                            'service' => $block->event->integration->service,
                            'name' => $block->event->integration->name,
                        ] : null,
                    ] : null,
                ];
            }),
            'meta' => [
                'query' => $query,
                'threshold' => $threshold,
                'limit' => $limit,
                'count' => $blocks->count(),
            ],
        ]);
    }

    /**
     * Perform unified semantic search across both events and blocks
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function searchAll(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'query' => 'required|string|min:1|max:500',
            'threshold' => 'nullable|numeric|min:0|max:2',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'details' => $validator->errors(),
            ], 422);
        }

        $query = $request->input('query');
        $threshold = $request->input('threshold', 1.0);
        $limit = $request->input('limit', 20);
        $perType = ceil($limit / 2); // Split limit between events and blocks

        // Generate embedding for the search query
        try {
            $embedding = $this->embeddingService->embed($query);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to generate embedding for query',
                'message' => $e->getMessage(),
            ], 500);
        }

        // Ensure user can only search their own data
        $userIntegrationIds = $request->user()->integrations()->pluck('id')->toArray();

        // Search events
        $events = Event::semanticSearch($embedding, $threshold, $perType)
            ->whereIn('integration_id', $userIntegrationIds)
            ->with(['integration', 'actor', 'target'])
            ->get();

        // Search blocks
        $blocks = Block::semanticSearch($embedding, $threshold, $perType)
            ->whereHas('event', function ($query) use ($userIntegrationIds) {
                $query->whereIn('integration_id', $userIntegrationIds);
            })
            ->with(['event.integration'])
            ->get();

        // Combine and sort by similarity
        $combinedResults = collect([
            ...$events->map(fn ($event) => [
                'type' => 'event',
                'data' => $event,
                'similarity' => $event->similarity ?? 999,
            ]),
            ...$blocks->map(fn ($block) => [
                'type' => 'block',
                'data' => $block,
                'similarity' => $block->similarity ?? 999,
            ]),
        ])->sortBy('similarity')->take($limit);

        return response()->json([
            'data' => $combinedResults->map(function ($result) {
                if ($result['type'] === 'event') {
                    $event = $result['data'];

                    return [
                        'type' => 'event',
                        'id' => $event->id,
                        'service' => $event->service,
                        'domain' => $event->domain,
                        'action' => $event->action,
                        'time' => $event->time,
                        'similarity' => round(1 - $result['similarity'], 4),
                        'integration' => $event->integration ? [
                            'id' => $event->integration->id,
                            'name' => $event->integration->name,
                        ] : null,
                    ];
                } else {
                    $block = $result['data'];

                    return [
                        'type' => 'block',
                        'id' => $block->id,
                        'title' => $block->title,
                        'content' => $block->getContent(),
                        'time' => $block->time,
                        'similarity' => round(1 - $result['similarity'], 4),
                        'event' => $block->event ? [
                            'id' => $block->event->id,
                            'service' => $block->event->service,
                        ] : null,
                    ];
                }
            })->values(),
            'meta' => [
                'query' => $query,
                'threshold' => $threshold,
                'limit' => $limit,
                'count' => $combinedResults->count(),
                'events_count' => $events->count(),
                'blocks_count' => $blocks->count(),
            ],
        ]);
    }
}
