<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresSparkAbility;
use App\Models\EventObject;
use App\Services\EffectiveTimezoneResolver;
use App\Services\Flint\FlintNoteService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Name('get-flint-notes')]
#[IsIdempotent]
#[IsReadOnly]
class GetFlintNotesTool extends Tool
{
    use RequiresSparkAbility;

    protected string $description = <<<'MARKDOWN'
        Retrieve the user's explicit Notes to Flint. Notes are user-authored intent and
        corrections, so they outrank inferred context and stale calendar or topic data.
        By default returns notes updated in the last 30 days. Use updated_since as a
        watermark for new notes, query for a bounded keyword search, or context_id for
        notes linked to a specific event, digest, block, or topic.
    MARKDOWN;

    public function __construct(
        private FlintNoteService $notes,
        private EffectiveTimezoneResolver $timezones,
    ) {}

    public function handle(Request $request): Response
    {
        if ($error = $this->requireAbility($request, 'flint:read')) {
            return $error;
        }

        $user = $request->user();
        if (! $user) {
            return Response::error('Authentication required.');
        }

        $limit = min(max((int) $request->get('limit', 50), 1), 50);
        $queryText = trim((string) $request->get('query', ''));
        $contextId = $request->get('context_id');
        $hasContext = is_string($contextId) && $contextId !== '';
        $updatedSinceInput = $request->get('updated_since');

        try {
            $updatedSince = is_string($updatedSinceInput) && $updatedSinceInput !== ''
                ? CarbonImmutable::parse($updatedSinceInput)->utc()
                : null;
        } catch (Throwable) {
            return Response::error('updated_since must be an ISO 8601 timestamp.');
        }

        // An unfiltered inbox read is deliberately bounded. A targeted query or
        // context lookup searches the durable note history unless the caller
        // supplies its own watermark.
        if ($updatedSince === null && $queryText === '' && ! $hasContext) {
            $updatedSince = now()->subDays(30);
        }

        $query = EventObject::query()
            ->where('user_id', $user->id)
            ->where('concept', 'document')
            ->where('type', 'flint_note')
            ->when($updatedSince !== null, fn ($query) => $query->where('updated_at', '>=', $updatedSince))
            ->when($queryText !== '', fn ($query) => $query->where(function ($nested) use ($queryText): void {
                $nested->where('title', 'ilike', "%{$queryText}%")
                    ->orWhere('content', 'ilike', "%{$queryText}%");
            }))
            ->when($hasContext, fn ($query) => $query->whereHas(
                'relationshipsFrom',
                fn ($relationship) => $relationship
                    ->where('type', FlintNoteService::RELATIONSHIP_TYPE)
                    ->where('to_id', strtolower($contextId)),
            ))
            ->with(['relationshipsFrom' => fn ($query) => $query->where('type', FlintNoteService::RELATIONSHIP_TYPE)])
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        $items = $query->limit($limit + 1)->get();
        $hasMore = $items->count() > $limit;
        $items = $items->take($limit);

        return Response::json([
            'data' => $items->map(fn (EventObject $note) => $this->notes->payload($note))->values()->all(),
            'count' => $items->count(),
            'has_more' => $hasMore,
            'watermark' => $items->max(fn (EventObject $note) => $note->updated_at?->toIso8601String()),
            'effective_timezone' => $this->timezones->timezoneFor($user),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'updated_since' => $schema->string()
                ->description('ISO 8601 watermark. An unfiltered read defaults to 30 days ago; targeted query/context lookups search all active notes.'),
            'query' => $schema->string()
                ->description('Optional case-insensitive keyword search across note title and body.'),
            'context_id' => $schema->string()
                ->description('Optional UUID of an event, digest, block, or topic to which the note is linked.'),
            'limit' => $schema->integer()
                ->description('Maximum notes to return, 1-50. Defaults to 50.')
                ->default(50),
        ];
    }
}
