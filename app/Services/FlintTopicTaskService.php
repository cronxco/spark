<?php

namespace App\Services;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\User;
use App\Services\Api\ResourceVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Tasks are real blocks, each on a private Flint event targeting its Topic.
 * A separate event per task permits identical titles and stable block IDs.
 */
class FlintTopicTaskService
{
    public function __construct(
        private FlintDigestService $digests,
        private ResourceVersion $versions,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function list(User $user, string $topicId): array
    {
        return $this->blocks($user, $topicId)
            ->orderByRaw("CASE WHEN blocks.metadata->>'completed_at' IS NULL THEN 0 ELSE 1 END")
            ->orderByRaw("COALESCE(blocks.metadata->>'due_on', blocks.metadata->>'review_on', '9999-12-31')")
            ->orderBy('blocks.created_at')
            ->get()
            ->map(fn (Block $block): array => $this->payload($block))
            ->all();
    }

    /** @param array<string, mixed> $data
     *  @return array<string, mixed>|null
     */
    public function create(User $user, string $topicId, array $data): ?array
    {
        $topic = $this->topic($user, $topicId);
        if (! $topic) {
            return null;
        }

        return DB::transaction(function () use ($user, $topic, $data): array {
            $integration = $this->digests->resolveIntegration($user);
            $sourceId = 'flint_topic_task:' . ($data['client_mutation_id'] ?? Str::uuid());
            $existing = Event::query()->where('integration_id', $integration->id)
                ->where('source_id', $sourceId)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->target_id !== $topic->id) {
                    abort(409, 'This mutation ID belongs to another thread.');
                }
                return $this->payload($existing->blocks()->where('block_type', 'flint_topic_task')->firstOrFail());
            }
            $actor = EventObject::firstOrCreate(
                ['user_id' => $user->id, 'concept' => 'user', 'type' => 'user_profile', 'title' => $user->name],
                ['time' => now()],
            );
            $event = Event::create([
                'source_id' => $sourceId,
                'integration_id' => $integration->id,
                'actor_id' => $actor->id,
                'target_id' => $topic->id,
                'service' => 'flint',
                'domain' => 'knowledge',
                'action' => 'had_topic_task',
                'time' => now(),
                'event_metadata' => ['internal' => true],
            ]);
            $block = $event->createBlock([
                'block_type' => 'flint_topic_task',
                'title' => $data['title'],
                'time' => now(),
                'metadata' => [
                    'content' => $data['content'] ?? null,
                    'due_on' => $data['due_on'] ?? null,
                    'review_on' => $data['review_on'] ?? null,
                    'completed_at' => null,
                ],
            ]);

            return $this->payload($block);
        });
    }

    /** @param array<string, mixed> $data
     *  @return array<string, mixed>|null
     */
    public function update(User $user, string $topicId, string $taskId, array $data, ?string $etag): ?array
    {
        if (! $this->topic($user, $topicId)) {
            return null;
        }
        return DB::transaction(function () use ($user, $topicId, $taskId, $data, $etag): ?array {
            $block = $this->blocks($user, $topicId)->lockForUpdate()->find($taskId);
            if (! $block) {
                return null;
            }
            if (! $etag || ! $this->versions->matches($block, $etag)) {
                $current = $this->versions->etag($block);
                throw new HttpResponseException(response()->json([
                    'message' => $etag ? 'The task has changed. Refresh and retry.' : 'An If-Match header is required.',
                    'etag' => $current,
                ], $etag ? 412 : 428)->header('ETag', $current));
            }
            $metadata = $block->metadata ?? [];
            foreach (['content', 'due_on', 'review_on'] as $key) {
                if (array_key_exists($key, $data)) {
                    $metadata[$key] = $data[$key];
                }
            }
            if (array_key_exists('completed', $data)) {
                $metadata['completed_at'] = $data['completed'] ? ($metadata['completed_at'] ?? now()->toIso8601String()) : null;
            }
            $block->update([
                'title' => $data['title'] ?? $block->title,
                'metadata' => $metadata,
            ]);

            return $this->payload($block);
        });
    }

    /** @return Builder<Block> */
    private function blocks(User $user, string $topicId): Builder
    {
        return Block::query()->where('block_type', 'flint_topic_task')
            ->whereHas('event', fn (Builder $query) => $query
                ->where('target_id', $topicId)
                ->where('service', 'flint')
                ->where('action', 'had_topic_task')
                ->whereHas('integration', fn (Builder $integration) => $integration->where('user_id', $user->id)));
    }

    private function topic(User $user, string $id): ?EventObject
    {
        return EventObject::query()->where('user_id', $user->id)
            ->where('concept', 'flint')->where('type', 'topic')->find($id);
    }

    /** @return array<string, mixed> */
    private function payload(Block $block): array
    {
        $metadata = $block->metadata ?? [];

        return [
            'id' => (string) $block->id,
            'title' => $block->title,
            'content' => $metadata['content'] ?? null,
            'due_on' => $metadata['due_on'] ?? null,
            'review_on' => $metadata['review_on'] ?? null,
            'completed_at' => $metadata['completed_at'] ?? null,
            'version' => $this->versions->etag($block),
        ];
    }
}
