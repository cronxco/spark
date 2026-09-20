<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresSparkAbility;
use App\Models\EventObject;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get-saved-bookmarks')]
#[IsIdempotent]
#[IsReadOnly]
class GetSavedBookmarksTool extends Tool
{
    use RequiresSparkAbility;

    protected string $description = <<<'MARKDOWN'
        List the user's bookmarks stored in Spark, newest first, with their enriched
        summary blocks. Use this instead of an external bookmark service when curating
        Flint's reading list. Results are deduplicated by bookmarked object.
    MARKDOWN;

    public function handle(Request $request): Response
    {
        if ($error = $this->requireAbility($request, 'data:read')) {
            return $error;
        }

        $user = $request->user();
        if (! $user) {
            return Response::error('Authentication required.');
        }

        $limit = min(max((int) $request->get('limit', 50), 1), 100);
        $queryText = trim((string) $request->get('query', ''));
        $bookmarks = EventObject::query()
            ->where('user_id', $user->id)
            ->where('concept', 'bookmark')
            ->when($queryText !== '', fn ($query) => $query->where(function ($nested) use ($queryText): void {
                $nested->where('title', 'ilike', "%{$queryText}%")
                    ->orWhere('content', 'ilike', "%{$queryText}%")
                    ->orWhereHas('targetEvents.blocks', fn ($block) => $block
                        ->whereRaw("metadata->>'content' ilike ?", ["%{$queryText}%"]));
            }))
            ->with(['latestTargetEvent' => fn ($event) => $event
                ->withoutInternal()
                ->whereHas('integration', fn ($integration) => $integration->where('user_id', $user->id))
                ->with('blocks')])
            ->orderByDesc('time')
            ->limit($limit)
            ->get()
            ->values();

        $summaryTypes = ['fetch_tldr', 'fetch_summary_paragraph', 'fetch_key_takeaways'];
        $items = $bookmarks->map(function (EventObject $bookmark) use ($summaryTypes): array {
            $event = $bookmark->latestTargetEvent;
            $blocks = $event?->blocks?->whereIn('block_type', $summaryTypes)->keyBy('block_type') ?? collect();

            return [
                'event_id' => $event ? (string) $event->id : null,
                'bookmark_id' => (string) $bookmark->id,
                'title' => $event?->displayTargetTitle() ?? $bookmark->title ?? 'Untitled',
                'url' => $event?->displayTargetUrl() ?? $bookmark->url,
                'saved_at' => $bookmark->time?->toIso8601String(),
                'updated_at' => ($event?->updated_at ?? $bookmark->updated_at)?->toIso8601String(),
                'tldr' => $blocks->get('fetch_tldr')?->getContent(),
                'summary' => $blocks->get('fetch_summary_paragraph')?->getContent(),
                'key_takeaways' => $blocks->get('fetch_key_takeaways')?->getContent(),
            ];
        });

        return Response::json([
            'data' => $items->all(),
            'count' => $items->count(),
            'limit' => $limit,
            'query' => $queryText !== '' ? $queryText : null,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Optional keyword filter across title and captured content.'),
            'limit' => $schema->integer()->description('Maximum distinct bookmarks, 1-100. Defaults to 50.')->default(50),
        ];
    }
}
