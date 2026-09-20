<?php

namespace App\Services;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Relationship;
use App\Models\User;
use App\Services\Api\EntityMutationService;
use App\Services\Api\ResourceVersion;
use App\Support\FlintTopicWatchingFor;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class FlintTopicService
{
    public function __construct(
        private EntityMutationService $mutations,
        private ResourceVersion $versions,
    ) {}

    /** @return array<string, mixed> */
    public function create(User $user, array $input, ?string $runUuid = null): array
    {
        $data = Validator::make($input, $this->rules(true))->validate();
        $now = now();

        $topic = EventObject::firstOrCreate(
            [
                'user_id' => $user->id,
                'concept' => 'flint',
                'type' => 'topic',
                'title' => $data['title'],
            ],
            [
                'content' => $data['content'] ?? null,
                'time' => $now,
                'metadata' => [
                    'kind' => $data['kind'],
                    'status' => $data['status'] ?? 'active',
                    'first_seen_at' => $now->toIso8601String(),
                    'last_touched_at' => $now->toIso8601String(),
                    'next_review_at' => $data['next_review_at'] ?? null,
                    'origin' => $data['origin'] ?? 'digest_inference',
                    'watching_for' => $data['watching_for'] ?? null,
                    'run_uuids' => $runUuid ? [$runUuid] : [],
                ],
            ],
        );

        if (! $topic->wasRecentlyCreated) {
            $topic = $this->updateTopic($topic, $data, $now, $runUuid);
        }

        $this->linkRelatedEntities($user, $topic, $data);

        return $this->payload($topic->fresh());
    }

    /** @return array<string, mixed>|null */
    public function update(User $user, string $id, array $input, ?string $runUuid = null): ?array
    {
        $data = Validator::make($input, $this->rules())->validate();
        $topic = $this->topics($user)->find($id);

        if (! $topic) {
            return null;
        }

        $topic = $this->updateTopic($topic, $data, now(), $runUuid);
        $this->linkRelatedEntities($user, $topic, $data);

        return $this->payload($topic->fresh());
    }

    /** @return array<string, mixed> */
    public function list(User $user, array $input): array
    {
        $data = Validator::make($input, [
            'status' => ['nullable', Rule::in(['active', 'dormant', 'resolved', 'expired'])],
            'kind' => ['nullable', Rule::in(['strategic', 'thematic', 'tactical'])],
        ])->validate();

        $topics = $this->topics($user)
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('metadata->status', $status))
            ->when($data['kind'] ?? null, fn ($query, $kind) => $query->where('metadata->kind', $kind))
            ->orderByDesc('updated_at')
            ->get();

        return ['data' => $topics->map(fn (EventObject $topic) => $this->payload($topic))->all()];
    }

    /**
     * Delete a topic the user owns. Its `discussed_in` links go with it.
     */
    public function delete(User $user, string $id): bool
    {
        $topic = $this->topics($user)->find($id);

        if (! $topic) {
            return false;
        }

        $topic->delete();

        return true;
    }

    /**
     * Every topic the user owns, optionally narrowed by status and kind.
     *
     * Exposed for the Flint web UI, which renders the models directly rather
     * than the flattened MCP payload.
     */
    public function query(User $user, ?string $status = null, ?string $kind = null): Builder
    {
        return $this->topics($user)
            ->when($status, fn (Builder $query) => $query->where('metadata->status', $status))
            ->when($kind, fn (Builder $query) => $query->where('metadata->kind', $kind));
    }

    /** @return array<string, mixed>|null */
    public function detail(User $user, string $id): ?array
    {
        $topic = $this->query($user)->find($id);
        if (! $topic) {
            return null;
        }

        return $this->payload($topic) + [
            'version' => $this->versions->etag($topic),
            'mentions' => $this->mentions($topic)->all(),
        ];
    }

    /**
     * How many topics the user has in each status, for the filter chips.
     *
     * @return array<string, int>
     */
    public function statusCounts(User $user): array
    {
        return $this->topics($user)
            ->get(['id', 'metadata'])
            ->countBy(fn (EventObject $topic) => $topic->metadata['status'] ?? 'active')
            ->all();
    }

    /**
     * The digest events and blocks that have discussed a topic, newest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function mentions(EventObject $topic, int $limit = 20): Collection
    {
        $relationships = Relationship::query()
            ->where('user_id', $topic->user_id)
            ->where('type', 'discussed_in')
            ->where(function ($query) use ($topic): void {
                $query->where(fn ($q) => $q->where('from_type', EventObject::class)->where('from_id', $topic->id))
                    ->orWhere(fn ($q) => $q->where('to_type', EventObject::class)->where('to_id', $topic->id));
            })
            ->get();

        return $relationships->map(function (Relationship $relationship) use ($topic): ?array {
            $sourceType = $relationship->from_type === EventObject::class && $relationship->from_id === $topic->id
                ? $relationship->to_type
                : $relationship->from_type;
            $sourceId = $relationship->from_type === EventObject::class && $relationship->from_id === $topic->id
                ? $relationship->to_id
                : $relationship->from_id;

            if ($sourceType === Event::class) {
                $event = Event::withTrashed()->whereHas('integration', fn ($query) => $query->where('user_id', $topic->user_id))->find($sourceId);
                if (! $event) {
                    return null;
                }

                $isDigest = $event->service === 'flint' && $event->action === 'had_summary';

                return [
                    'id' => (string) $relationship->id,
                    'kind' => 'event',
                    'source_type' => $isDigest ? 'digest' : 'event',
                    'source_id' => (string) $event->id,
                    'event_id' => (string) $event->id,
                    'digest_id' => $isDigest ? (string) $event->id : null,
                    'block_id' => null,
                    'title' => data_get($event->event_metadata, 'title', $event->action),
                    'detail' => data_get($event->event_metadata, 'period'),
                    'excerpt' => data_get($event->event_metadata, 'summary'),
                    'local_date' => data_get($event->event_metadata, 'local_date', $event->time?->toDateString()),
                    'period' => data_get($event->event_metadata, 'period'),
                    'occurred_at' => $event->time?->toIso8601String(),
                    'deep_link' => ($isDigest ? 'spark://digest/' : 'spark://event/').$event->id,
                    'source_deleted' => $event->trashed(),
                ];
            }

            if ($sourceType === Block::class) {
                $block = Block::withTrashed()
                    ->whereHas('event.integration', fn ($query) => $query->where('user_id', $topic->user_id))
                    ->with('event')
                    ->find($sourceId);
                if (! $block) {
                    return null;
                }

                $occurredAt = $block->time ?? $block->event?->time;
                $isDigest = $block->event?->service === 'flint' && $block->event?->action === 'had_summary';

                return [
                    'id' => (string) $relationship->id,
                    'kind' => 'block',
                    'source_type' => $isDigest ? 'digest_block' : 'block',
                    'source_id' => (string) $block->id,
                    'event_id' => (string) $block->event_id,
                    'digest_id' => $isDigest ? (string) $block->event_id : null,
                    'block_id' => (string) $block->id,
                    'title' => $block->title ?: 'Deleted digest evidence',
                    'detail' => $block->block_type,
                    'excerpt' => $block->getContent(),
                    'local_date' => data_get($block->event?->event_metadata, 'local_date', $occurredAt?->toDateString()),
                    'period' => data_get($block->event?->event_metadata, 'period'),
                    'occurred_at' => $occurredAt?->toIso8601String(),
                    'deep_link' => 'spark://block/'.$block->id,
                    'source_deleted' => $block->trashed(),
                ];
            }

            return null;
        })->filter()
            ->sortByDesc(fn (array $mention) => ($mention['occurred_at'] ?? '').':'.$mention['id'])
            ->unique(fn (array $mention) => $mention['source_type'].':'.$mention['source_id'])
            ->take($limit)
            ->values();
    }

    /** @return Builder<EventObject> */
    private function topics(User $user): Builder
    {
        return EventObject::query()
            ->where('user_id', $user->id)
            ->where('concept', 'flint')
            ->where('type', 'topic');
    }

    private function updateTopic(EventObject $topic, array $data, DateTimeInterface $now, ?string $runUuid = null): EventObject
    {
        $attributes = Arr::only($data, ['title', 'content']);
        $metadata = $topic->metadata ?? [];

        foreach (['kind', 'status', 'next_review_at', 'origin', 'watching_for'] as $key) {
            if (array_key_exists($key, $data)) {
                $metadata[$key] = $data[$key];
            }
        }

        $metadata['first_seen_at'] ??= $now->format(DATE_ATOM);
        $metadata['last_touched_at'] = $now->format(DATE_ATOM);
        if ($runUuid !== null) {
            $metadata['run_uuids'] = collect($metadata['run_uuids'] ?? [])
                ->filter(fn (mixed $run): bool => is_string($run))
                ->push($runUuid)
                ->unique()
                ->take(-20)
                ->values()
                ->all();
        }
        $attributes['metadata'] = $metadata;
        $attributes['time'] = $now;

        $topic->update($attributes);

        return $topic;
    }

    private function linkRelatedEntities(User $user, EventObject $topic, array $data): void
    {
        foreach (['related_event_id' => 'event', 'related_block_id' => 'block'] as $field => $kind) {
            if (! empty($data[$field])) {
                $this->mutations->createRelationship($user, 'object', $topic->id, [
                    'to_kind' => $kind,
                    'to_id' => $data[$field],
                    'type' => 'discussed_in',
                ]);
            }
        }
    }

    /** @return array<string, mixed> */
    private function payload(EventObject $topic): array
    {
        return [
            'id' => $topic->id,
            'title' => $topic->title,
            'content' => $topic->content,
            'kind' => $topic->metadata['kind'] ?? null,
            'status' => $topic->metadata['status'] ?? null,
            'first_seen_at' => $topic->metadata['first_seen_at'] ?? null,
            'last_touched_at' => $topic->metadata['last_touched_at'] ?? null,
            'next_review_at' => $topic->metadata['next_review_at'] ?? null,
            'origin' => $topic->metadata['origin'] ?? null,
            // MR-10: what would move this thread on, published explicitly so
            // the client owns no sentence-splitting of `content`.
            'watching_for' => $topic->metadata['watching_for']
                ?? FlintTopicWatchingFor::extract($topic->content),
        ];
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(bool $creating = false): array
    {
        return [
            // `sometimes` + `required` so an update may omit the title, but may not
            // blank it — the title is the topic's only identifier in the UI.
            'title' => $creating ? ['required', 'string', 'max:255'] : ['sometimes', 'required', 'string', 'max:255'],
            'content' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'kind' => [$creating ? 'required' : 'sometimes', Rule::in(['strategic', 'thematic', 'tactical'])],
            'status' => ['sometimes', Rule::in(['active', 'dormant', 'resolved', 'expired'])],
            'next_review_at' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'origin' => ['sometimes', Rule::in(['conversation', 'digest_inference'])],
            'watching_for' => ['sometimes', 'nullable', 'string', 'max:500'],
            'related_event_id' => ['sometimes', 'nullable', 'uuid'],
            'related_block_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
