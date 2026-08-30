<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\User;
use App\Services\Api\EntityMutationService;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class FlintTopicService
{
    public function __construct(private EntityMutationService $mutations) {}

    /** @return array<string, mixed> */
    public function create(User $user, array $input): array
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
                ],
            ],
        );

        if (! $topic->wasRecentlyCreated) {
            $topic = $this->updateTopic($topic, $data, $now);
        }

        $this->linkRelatedEntities($user, $topic, $data);

        return $this->payload($topic->fresh());
    }

    /** @return array<string, mixed>|null */
    public function update(User $user, string $id, array $input): ?array
    {
        $data = Validator::make($input, $this->rules())->validate();
        $topic = $this->topics($user)->find($id);

        if (! $topic) {
            return null;
        }

        $topic = $this->updateTopic($topic, $data, now());
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
        $events = $topic->relatedEvents('discussed_in')
            ->latest('time')
            ->limit($limit)
            ->get()
            ->map(fn (Event $event) => [
                'kind' => 'event',
                'id' => $event->id,
                'time' => $event->time,
                'title' => $event->event_metadata['title'] ?? $event->action,
                'detail' => $event->event_metadata['period'] ?? null,
            ]);

        $blocks = $topic->relatedBlocks('discussed_in')
            ->latest('time')
            ->limit($limit)
            ->get()
            ->map(fn ($block) => [
                'kind' => 'block',
                'id' => $block->id,
                'time' => $block->time,
                'title' => $block->title,
                'detail' => $block->block_type,
            ]);

        return $events->concat($blocks)
            ->sortByDesc(fn (array $mention) => $mention['time'])
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

    private function updateTopic(EventObject $topic, array $data, DateTimeInterface $now): EventObject
    {
        $attributes = Arr::only($data, ['title', 'content']);
        $metadata = $topic->metadata ?? [];

        foreach (['kind', 'status', 'next_review_at', 'origin'] as $key) {
            if (array_key_exists($key, $data)) {
                $metadata[$key] = $data[$key];
            }
        }

        $metadata['first_seen_at'] ??= $now->format(DATE_ATOM);
        $metadata['last_touched_at'] = $now->format(DATE_ATOM);
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
            'related_event_id' => ['sometimes', 'nullable', 'uuid'],
            'related_block_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
