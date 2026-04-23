<?php

namespace App\Mcp\Tools;

use App\Http\Resources\EventObjectResource;
use App\Models\EventObject;
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
class SearchObjectsTool extends Tool
{
    /**
     * The tool's description.
     */
    protected string $description = <<<'MARKDOWN'
        Search for objects (entities) using semantic (vector similarity) or keyword search.
        Objects represent entities like users, accounts, tracks, playlists, merchants, etc.
        Returns matching objects with similarity scores when using semantic search.
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

        $semantic = $request->boolean('semantic', true);
        $limit = max(1, min((int) $request->get('limit', 20), 50));
        $concept = $request->get('concept');
        $type = $request->get('type');

        try {
            if ($semantic) {
                // Generate embedding for semantic search
                $embedding = $this->embeddingService->embed($query);

                // Build filters
                $filters = array_filter([
                    'concept' => $concept,
                    'type' => $type,
                    'user_id' => $user->id,
                ]);

                // Perform hybrid search with semantic + filters
                $objects = EventObject::hybridSearch($embedding, $filters, threshold: 1.2, limit: $limit)
                    ->get();
            } else {
                // Keyword search (basic LIKE search)
                $objects = EventObject::query()
                    ->where('user_id', $user->id)
                    ->where(function ($q) use ($query) {
                        $q->where('title', 'ILIKE', "%{$query}%")
                            ->orWhere('content', 'ILIKE', "%{$query}%")
                            ->orWhere('concept', 'ILIKE', "%{$query}%")
                            ->orWhere('type', 'ILIKE', "%{$query}%");
                    })
                    ->when($concept, fn ($q) => $q->where('concept', $concept))
                    ->when($type, fn ($q) => $q->where('type', $type))
                    ->orderBy('time', 'desc')
                    ->limit($limit)
                    ->get();
            }

            $results = [
                'objects' => EventObjectResource::collection($objects)->resolve(request()),
                'meta' => [
                    'query' => $query,
                    'semantic' => $semantic,
                    'count' => $objects->count(),
                    'limit' => $limit,
                ],
            ];

            // Add similarity scores if available
            if ($semantic && $objects->isNotEmpty()) {
                $results['objects'] = $objects->map(function ($object) {
                    $data = (new EventObjectResource($object))->resolve(request());
                    if (isset($object->similarity)) {
                        $data['similarity'] = round(1 - $object->similarity, 4);
                    }

                    return $data;
                })->values()->toArray();
            }

            return Response::text(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (Exception $e) {
            report($e);

            return Response::error('Search failed. Please try again later.');
        }
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, JsonSchema>
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

            'concept' => $schema->string()
                ->description('Filter by concept (e.g., user, track, account, merchant, place).'),

            'type' => $schema->string()
                ->description('Filter by type (e.g., spotify_track, monzo_merchant, oura_user).'),

            'limit' => $schema->integer()
                ->description('Maximum number of results (default: 20, max: 50).')
                ->default(20),
        ];
    }
}
