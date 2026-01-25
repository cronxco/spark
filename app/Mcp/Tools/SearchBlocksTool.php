<?php

namespace App\Mcp\Tools;

use App\Http\Resources\BlockResource;
use App\Models\Block;
use App\Services\EmbeddingService;
use Exception;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsIdempotent]
#[IsReadOnly]
class SearchBlocksTool extends Tool
{
    /**
     * The tool's description.
     */
    protected string $description = <<<'MARKDOWN'
        Search for blocks using semantic (vector similarity) or keyword search.
        Blocks are data units attached to events containing content like summaries, metrics, details, or media.
        Returns matching blocks with similarity scores when using semantic search.
    MARKDOWN;

    public function __construct(
        protected EmbeddingService $embeddingService
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $user = $request->user();

        if (! $user) {
            return Response::error('Authentication required.');
        }

        $query = $request->get('query');

        if (empty($query)) {
            return Response::error('Query parameter is required.');
        }

        $semantic = $request->get('semantic', true);
        $limit = min((int) $request->get('limit', 20), 50);
        $blockType = $request->get('block_type');
        $fromDate = $request->get('from_date');
        $toDate = $request->get('to_date');

        // Get user's integration IDs for authorization
        $userIntegrationIds = $user->integrations()->pluck('id')->toArray();

        if (empty($userIntegrationIds)) {
            return Response::text(json_encode([
                'blocks' => [],
                'meta' => [
                    'query' => $query,
                    'count' => 0,
                    'message' => 'No integrations found for user.',
                ],
            ], JSON_PRETTY_PRINT));
        }

        try {
            if ($semantic) {
                // Generate embedding for semantic search
                $embedding = $this->embeddingService->embed($query);

                // Build filters
                $filters = array_filter([
                    'block_type' => $blockType,
                    'from_date' => $fromDate,
                    'to_date' => $toDate,
                ]);

                // Perform hybrid search with semantic + filters
                $blocks = Block::hybridSearch($embedding, $filters, threshold: 1.2, limit: $limit)
                    ->whereHas('event', fn ($q) => $q->whereIn('integration_id', $userIntegrationIds))
                    ->with(['event.integration'])
                    ->get();
            } else {
                // Keyword search (basic LIKE search)
                $blocks = Block::query()
                    ->whereHas('event', fn ($q) => $q->whereIn('integration_id', $userIntegrationIds))
                    ->where(function ($q) use ($query) {
                        $q->where('title', 'ILIKE', "%{$query}%")
                            ->orWhere('block_type', 'ILIKE', "%{$query}%")
                            ->orWhereRaw("metadata->>'content' ILIKE ?", ["%{$query}%"]);
                    })
                    ->when($blockType, fn ($q) => $q->where('block_type', $blockType))
                    ->when($fromDate, fn ($q) => $q->where('time', '>=', $fromDate))
                    ->when($toDate, fn ($q) => $q->where('time', '<=', $toDate))
                    ->with(['event.integration'])
                    ->orderBy('time', 'desc')
                    ->limit($limit)
                    ->get();
            }

            $results = [
                'blocks' => BlockResource::collection($blocks)->resolve(request()),
                'meta' => [
                    'query' => $query,
                    'semantic' => $semantic,
                    'count' => $blocks->count(),
                    'limit' => $limit,
                ],
            ];

            // Add similarity scores if available
            if ($semantic && $blocks->isNotEmpty()) {
                $results['blocks'] = $blocks->map(function ($block) {
                    $data = (new BlockResource($block))->resolve(request());
                    if (isset($block->similarity)) {
                        $data['similarity'] = round(1 - $block->similarity, 4);
                    }

                    return $data;
                })->values()->toArray();
            }

            return Response::text(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (Exception $e) {
            return Response::error('Search failed: ' . $e->getMessage());
        }
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Search query text.')
                ->required(),

            'semantic' => $schema->boolean()
                ->description('Enable semantic (vector similarity) search. Default: true.')
                ->default(true),

            'block_type' => $schema->string()
                ->description('Filter by block type (e.g., fetch_summary_paragraph, heart_rate, track_details).'),

            'from_date' => $schema->string()
                ->description('Filter blocks from this date (ISO format: YYYY-MM-DD).'),

            'to_date' => $schema->string()
                ->description('Filter blocks until this date (ISO format: YYYY-MM-DD).'),

            'limit' => $schema->integer()
                ->description('Maximum number of results (default: 20, max: 50).')
                ->default(20),
        ];
    }
}
