<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\User;
use App\Services\Mobile\EventLookup;
use App\Services\Mobile\ObjectLookup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Tags\Tag;

class TagsController extends Controller
{
    private const DEFAULT_LIMIT = 30;

    private const MAX_LIMIT = 100;

    public function __construct(
        protected EventLookup $eventLookup,
        protected ObjectLookup $objectLookup,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'cursor' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_LIMIT],
        ]);

        $tags = $this->withTotals(
            $this->tagQuery($request->user(), $validated['q'] ?? null)->get(),
        )->sortBy([['total_count', 'desc'], ['id', 'asc']])->values();

        [$tags, $nextCursor, $hasMore] = $this->paginate(
            $tags,
            $validated['cursor'] ?? null,
            (int) ($validated['limit'] ?? self::DEFAULT_LIMIT),
        );

        return response()->json([
            'data' => $tags->map(fn (Tag $tag) => $this->tagPayload($tag))->values(),
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'cursor' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_LIMIT],
        ]);

        $tag = $this->tagQuery($request->user())
            ->where('tags.id', $id)
            ->first();

        if (! $tag) {
            return response()->json(['message' => 'Tag not found.'], 404);
        }

        $tag = $this->withTotals(collect([$tag]))->first();
        $items = $this->taggedItems($request->user(), $tag);
        [$page, $nextCursor, $hasMore] = $this->paginate(
            $items,
            $validated['cursor'] ?? null,
            (int) ($validated['limit'] ?? self::DEFAULT_LIMIT),
        );

        return response()->json([
            'tag' => $this->tagPayload($tag),
            'data' => $page->map(fn (array $item) => $item['payload'])->values(),
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
        ]);
    }

    public function suggest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:25'],
        ]);
        $queryText = trim((string) ($validated['q'] ?? ''));
        $limit = (int) ($validated['limit'] ?? 10);

        $lower = mb_strtolower($queryText);
        $tags = $this->withTotals(
            $this->tagQuery($request->user(), $queryText)->get(),
        )->sortBy(function (Tag $tag) use ($lower) {
            $name = mb_strtolower((string) $tag->name);
            $matchRank = match (true) {
                $lower === '' => 0,
                $name === $lower => 0,
                str_starts_with($name, $lower) => 1,
                default => 2,
            };

            return [
                $matchRank,
                -((int) $tag->total_count),
                (string) $tag->id,
            ];
        })->take($limit)->values();

        return response()->json([
            'data' => $tags->map(fn (Tag $tag) => $this->tagPayload($tag))->values(),
        ]);
    }

    public function storeEventTag(Request $request, string $id): JsonResponse
    {
        $event = $this->eventLookup->find($request->user(), $id);
        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        return $this->attach($request, $event);
    }

    public function destroyEventTag(Request $request, string $id, string $tagId): JsonResponse
    {
        $event = $this->eventLookup->find($request->user(), $id);
        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        return $this->detach($event, $tagId);
    }

    public function storeObjectTag(Request $request, string $id): JsonResponse
    {
        $object = $this->objectLookup->find($request->user(), $id);
        if (! $object) {
            return response()->json(['message' => 'Object not found.'], 404);
        }

        return $this->attach($request, $object);
    }

    public function destroyObjectTag(Request $request, string $id, string $tagId): JsonResponse
    {
        $object = $this->objectLookup->find($request->user(), $id);
        if (! $object) {
            return response()->json(['message' => 'Object not found.'], 404);
        }

        return $this->detach($object, $tagId);
    }

    private function attach(Request $request, Event|EventObject $entity): JsonResponse
    {
        $validated = $request->validate([
            'tag_id' => ['nullable', 'string', Rule::exists('tags', 'id')],
            'name' => ['required_without:tag_id', 'nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:100'],
        ]);

        if (isset($validated['tag_id'])) {
            $tag = Tag::findOrFail($validated['tag_id']);
        } else {
            [$name, $type] = $this->normaliseTag(
                (string) $validated['name'],
                $validated['type'] ?? null,
            );
            if ($name === '') {
                return response()->json(['message' => 'The tag name field is required.'], 422);
            }

            $tag = Tag::findOrCreate($name, $type);
        }

        $entity->attachTags([$tag]);
        $entity->load('tags');

        return response()->json([
            'tag' => $this->tagPayload($tag),
            'tags' => $entity->tags->map(fn (Tag $item) => $this->tagPayload($item))->values(),
        ], 201);
    }

    private function detach(Event|EventObject $entity, string $tagId): JsonResponse
    {
        $tag = Tag::find($tagId);
        if (! $tag || ! $entity->tags()->where('tags.id', $tagId)->exists()) {
            return response()->json(['message' => 'Tag not found on entity.'], 404);
        }

        $entity->detachTags([$tag]);
        $entity->load('tags');

        return response()->json([
            'tags' => $entity->tags->map(fn (Tag $item) => $this->tagPayload($item))->values(),
        ]);
    }

    private function tagQuery(User $user, ?string $search = null): Builder
    {
        $integrationIds = $user->integrations()->pluck('id');

        $query = Tag::query()
            ->select('tags.*')
            ->selectSub(
                Event::query()
                    ->selectRaw('COUNT(*)')
                    ->join('taggables', function ($join) {
                        $join->on('taggables.taggable_id', '=', 'events.id')
                            ->where('taggables.taggable_type', Event::class);
                    })
                    ->whereColumn('taggables.tag_id', 'tags.id')
                    ->whereIn('events.integration_id', $integrationIds),
                'events_count',
            )
            ->selectSub(
                EventObject::query()
                    ->selectRaw('COUNT(*)')
                    ->join('taggables', function ($join) {
                        $join->on('taggables.taggable_id', '=', 'objects.id')
                            ->where('taggables.taggable_type', EventObject::class);
                    })
                    ->whereColumn('taggables.tag_id', 'tags.id')
                    ->where('objects.user_id', $user->id),
                'objects_count',
            )
            ->where(function (Builder $query) use ($user, $integrationIds) {
                $query->whereExists(function ($events) use ($integrationIds) {
                    $events->selectRaw('1')
                        ->from('taggables')
                        ->join('events', 'events.id', '=', 'taggables.taggable_id')
                        ->whereColumn('taggables.tag_id', 'tags.id')
                        ->where('taggables.taggable_type', Event::class)
                        ->whereIn('events.integration_id', $integrationIds);
                })->orWhereExists(function ($objects) use ($user) {
                    $objects->selectRaw('1')
                        ->from('taggables')
                        ->join('objects', 'objects.id', '=', 'taggables.taggable_id')
                        ->whereColumn('taggables.tag_id', 'tags.id')
                        ->where('taggables.taggable_type', EventObject::class)
                        ->where('objects.user_id', $user->id);
                });
            });

        $search = trim((string) $search);
        if ($search !== '') {
            $query->where(function (Builder $query) use ($search) {
                $query->where('name->en', 'ilike', '%' . $search . '%')
                    ->orWhere('type', 'ilike', '%' . $search . '%');
            });
        }

        return $query;
    }

    private function withTotals(Collection $tags): Collection
    {
        return $tags->each(function (Tag $tag) {
            $tag->setAttribute(
                'total_count',
                (int) $tag->events_count + (int) $tag->objects_count,
            );
        });
    }

    private function taggedItems(User $user, Tag $tag): Collection
    {
        $integrationIds = $user->integrations()->pluck('id');

        $events = Event::query()
            ->withAnyTags([$tag])
            ->whereIn('integration_id', $integrationIds)
            ->with(['actor', 'target'])
            ->get()
            ->map(fn (Event $event) => [
                'sort_time' => $event->time ?? $event->created_at,
                'payload' => [
                    'kind' => 'event',
                    'id' => (string) $event->id,
                    'title' => $event->target?->title ?? $event->actor?->title ?? format_action_title($event->action),
                    'subtitle' => $event->time?->toIso8601String(),
                    'domain' => $event->domain,
                ],
            ]);

        $objects = EventObject::query()
            ->withAnyTags([$tag])
            ->where('user_id', $user->id)
            ->get()
            ->map(fn (EventObject $object) => [
                'sort_time' => $object->time ?? $object->created_at,
                'payload' => [
                    'kind' => 'object',
                    'id' => (string) $object->id,
                    'title' => $object->title,
                    'subtitle' => $object->concept,
                    'concept' => $object->concept,
                ],
            ]);

        $blockIds = DB::table('taggables')
            ->where('tag_id', $tag->id)
            ->where('taggable_type', Block::class)
            ->pluck('taggable_id');

        $blocks = Block::query()
            ->whereIn('id', $blockIds)
            ->whereHas('event', fn (Builder $events) => $events->whereIn('integration_id', $integrationIds))
            ->get()
            ->map(fn (Block $block) => [
                'sort_time' => $block->time ?? $block->created_at,
                'payload' => [
                    'kind' => 'block',
                    'id' => (string) $block->id,
                    'title' => $block->title,
                    'subtitle' => $block->block_type,
                    'block_type' => $block->block_type,
                ],
            ]);

        return $events
            ->concat($objects)
            ->concat($blocks)
            ->sortByDesc('sort_time')
            ->values();
    }

    private function tagPayload(Tag $tag): array
    {
        return [
            'id' => (string) $tag->id,
            'name' => $tag->name,
            'type' => $tag->type,
            'events_count' => (int) ($tag->events_count ?? 0),
            'objects_count' => (int) ($tag->objects_count ?? 0),
            'total_count' => (int) ($tag->total_count ?? 0),
        ];
    }

    private function normaliseTag(string $value, ?string $type): array
    {
        $name = trim($value);
        $detectedType = $type !== null ? trim($type) : null;

        if ($detectedType === null && preg_match('/^([A-Za-z0-9-]+)[_:](.+)$/', $name, $matches) === 1) {
            $detectedType = strtolower($matches[1]);
            $name = trim($matches[2]);
        } elseif ($detectedType !== null
            && preg_match('/^' . preg_quote($detectedType, '/') . '[_:](.+)$/i', $name, $matches) === 1) {
            $name = trim($matches[1]);
        }

        $detectedType ??= preg_match('/^\p{Extended_Pictographic}(?:[\x{FE0F}\x{FE0E}])?(?:\x{200D}\p{Extended_Pictographic}(?:[\x{FE0F}\x{FE0E}])?)*$/u', $name) === 1
            ? 'emoji'
            : 'spark';

        return [$name, $detectedType];
    }

    private function paginate(Collection $items, ?string $cursor, int $limit): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $offset = $cursor ? (int) base64_decode(strtr($cursor, '-_', '+/')) : 0;
        $page = $items->slice($offset, $limit)->values();
        $nextOffset = $offset + $page->count();
        $hasMore = $nextOffset < $items->count();
        $nextCursor = $hasMore
            ? rtrim(strtr(base64_encode((string) $nextOffset), '+/', '-_'), '=')
            : null;

        return [$page, $nextCursor, $hasMore];
    }
}
