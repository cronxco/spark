<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Services\EmbeddingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class SemanticSearchController extends Controller
{
    public function __construct(
        private EmbeddingService $embeddingService
    ) {}

    /**
     * Perform semantic search across events, blocks, and objects
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function search(Request $request): JsonResponse
    {
        // Validate request
        $validator = Validator::make($request->all(), [
            'query' => 'required|string|max:1000',
            'models' => 'nullable|array',
            'models.*' => 'in:events,blocks,objects',
            'threshold' => 'nullable|numeric|min:0|max:2',
            'limit' => 'nullable|integer|min:1|max:100',
            'temporal_weight' => 'nullable|numeric|min:0|max:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors(),
            ], 422);
        }

        $query = $request->input('query');
        $models = $request->input('models', ['events', 'blocks', 'objects']);
        $threshold = $request->input('threshold', 1.2);
        $limit = $request->input('limit', 10);
        $temporalWeight = $request->input('temporal_weight', 0.015);

        // Get user from auth
        $user = Auth::guard('sanctum')->user();
        if (! $user) {
            return response()->json([
                'error' => 'Unauthorized',
            ], 401);
        }

        try {
            // Generate embedding for query
            $embedding = $this->embeddingService->embed($query);

            $results = [];

            // Search each requested model type
            if (in_array('events', $models)) {
                $results['events'] = $this->searchEvents($embedding, $user->id, $threshold, $limit, $temporalWeight);
            }

            if (in_array('blocks', $models)) {
                $results['blocks'] = $this->searchBlocks($embedding, $user->id, $threshold, $limit, $temporalWeight);
            }

            if (in_array('objects', $models)) {
                $results['objects'] = $this->searchObjects($embedding, $user->id, $threshold, $limit, $temporalWeight);
            }

            return response()->json([
                'success' => true,
                'query' => $query,
                'threshold' => $threshold,
                'limit' => $limit,
                'results' => $results,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Search failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Search events
     *
     * @param array $embedding
     * @param string $userId
     * @param float $threshold
     * @param int $limit
     * @param float $temporalWeight
     * @return array
     */
    private function searchEvents(array $embedding, string $userId, float $threshold, int $limit, float $temporalWeight): array
    {
        $userIntegrationIds = Integration::where('user_id', $userId)->pluck('id')->toArray();

        $events = Event::semanticSearch($embedding, threshold: $threshold, limit: $limit, temporalWeight: $temporalWeight)
            ->whereIn('integration_id', $userIntegrationIds)
            ->with(['actor', 'target', 'integration'])
            ->get();

        return $events->map(function ($event) {
            return [
                'id' => $event->id,
                'action' => $event->action,
                'service' => $event->service,
                'domain' => $event->domain,
                'time' => $event->time?->toIso8601String(),
                'value' => $event->formatted_value,
                'value_unit' => $event->value_unit,
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
                'similarity' => $event->similarity ?? null,
                'days_ago' => $event->days_ago ?? null,
                'url' => route('events.show', $event->id),
            ];
        })->toArray();
    }

    /**
     * Search blocks
     *
     * @param array $embedding
     * @param string $userId
     * @param float $threshold
     * @param int $limit
     * @param float $temporalWeight
     * @return array
     */
    private function searchBlocks(array $embedding, string $userId, float $threshold, int $limit, float $temporalWeight): array
    {
        $userIntegrationIds = Integration::where('user_id', $userId)->pluck('id')->toArray();

        $blocks = Block::semanticSearch($embedding, threshold: $threshold, limit: $limit, temporalWeight: $temporalWeight)
            ->whereHas('event', function ($q) use ($userIntegrationIds) {
                $q->whereIn('integration_id', $userIntegrationIds);
            })
            ->with(['event.integration'])
            ->get();

        return $blocks->map(function ($block) {
            return [
                'id' => $block->id,
                'title' => $block->title,
                'block_type' => $block->block_type,
                'time' => $block->time?->toIso8601String(),
                'value' => $block->formatted_value,
                'value_unit' => $block->value_unit,
                'url' => $block->url,
                'event' => $block->event ? [
                    'id' => $block->event->id,
                    'action' => $block->event->action,
                    'service' => $block->event->service,
                ] : null,
                'similarity' => $block->similarity ?? null,
                'days_ago' => $block->days_ago ?? null,
                'url_detail' => route('blocks.show', $block->id),
            ];
        })->toArray();
    }

    /**
     * Search objects
     *
     * @param array $embedding
     * @param string $userId
     * @param float $threshold
     * @param int $limit
     * @param float $temporalWeight
     * @return array
     */
    private function searchObjects(array $embedding, string $userId, float $threshold, int $limit, float $temporalWeight): array
    {
        $objects = EventObject::semanticSearch($embedding, threshold: $threshold, limit: $limit, temporalWeight: $temporalWeight)
            ->where('user_id', $userId)
            ->get();

        return $objects->map(function ($object) {
            return [
                'id' => $object->id,
                'title' => $object->title,
                'concept' => $object->concept,
                'type' => $object->type,
                'time' => $object->time?->toIso8601String(),
                'similarity' => $object->similarity ?? null,
                'days_ago' => $object->days_ago ?? null,
                'url' => route('objects.show', $object->id),
            ];
        })->toArray();
    }
}
