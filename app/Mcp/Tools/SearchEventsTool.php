<?php

namespace App\Mcp\Tools;

use App\Http\Resources\EventResource;
use App\Models\Event;
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
class SearchEventsTool extends Tool
{
    /**
     * The tool's description.
     */
    protected string $description = <<<'MARKDOWN'
        Search for events using semantic (vector similarity) or keyword search.
        Events represent timestamped activities like transactions, workouts, media plays, etc.
        Returns matching events with similarity scores when using semantic search.
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
        $service = $request->get('service');
        $domain = $request->get('domain');
        $fromDate = $request->get('from_date');
        $toDate = $request->get('to_date');

        // Get user's integration IDs for authorization
        $userIntegrationIds = $user->integrations()->pluck('id')->toArray();

        if (empty($userIntegrationIds)) {
            return Response::text(json_encode([
                'events' => [],
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
                    'service' => $service,
                    'domain' => $domain,
                    'from_date' => $fromDate,
                    'to_date' => $toDate,
                ]);

                // Perform hybrid search with semantic + filters
                $events = Event::hybridSearch($embedding, $filters, threshold: 1.2, limit: $limit)
                    ->whereIn('integration_id', $userIntegrationIds)
                    ->with(['integration', 'actor', 'target', 'blocks', 'tags'])
                    ->get();
            } else {
                // Keyword search (basic LIKE search)
                $events = Event::query()
                    ->whereIn('integration_id', $userIntegrationIds)
                    ->where(function ($q) use ($query) {
                        $q->where('action', 'ILIKE', "%{$query}%")
                            ->orWhere('service', 'ILIKE', "%{$query}%")
                            ->orWhereHas('actor', fn ($q2) => $q2->where('title', 'ILIKE', "%{$query}%"))
                            ->orWhereHas('target', fn ($q2) => $q2->where('title', 'ILIKE', "%{$query}%"));
                    })
                    ->when($service, fn ($q) => $q->where('service', $service))
                    ->when($domain, fn ($q) => $q->where('domain', $domain))
                    ->when($fromDate, fn ($q) => $q->where('time', '>=', $fromDate))
                    ->when($toDate, fn ($q) => $q->where('time', '<=', $toDate))
                    ->with(['integration', 'actor', 'target', 'blocks', 'tags'])
                    ->orderBy('time', 'desc')
                    ->limit($limit)
                    ->get();
            }

            $results = [
                'events' => EventResource::collection($events)->resolve(request()),
                'meta' => [
                    'query' => $query,
                    'semantic' => $semantic,
                    'count' => $events->count(),
                    'limit' => $limit,
                ],
            ];

            // Add similarity scores if available
            if ($semantic && $events->isNotEmpty()) {
                $results['events'] = $events->map(function ($event) {
                    $data = (new EventResource($event))->resolve(request());
                    if (isset($event->similarity)) {
                        $data['similarity'] = round(1 - $event->similarity, 4);
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

            'service' => $schema->string()
                ->description('Filter by service (e.g., monzo, oura, spotify, github).'),

            'domain' => $schema->string()
                ->enum(['health', 'money', 'media', 'knowledge', 'online'])
                ->description('Filter by domain.'),

            'from_date' => $schema->string()
                ->description('Filter events from this date (ISO format: YYYY-MM-DD).'),

            'to_date' => $schema->string()
                ->description('Filter events until this date (ISO format: YYYY-MM-DD).'),

            'limit' => $schema->integer()
                ->description('Maximum number of results (default: 20, max: 50).')
                ->default(20),
        ];
    }
}
