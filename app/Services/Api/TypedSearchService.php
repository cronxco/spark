<?php

namespace App\Services\Api;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\User;
use App\Services\EmbeddingService;
use Illuminate\Database\Eloquent\Collection;

/** Ownership-scoped typed search shared by API transports. */
class TypedSearchService
{
    public function __construct(private EmbeddingService $embeddings) {}

    /** @return Collection<int, Event|EventObject|Block> */
    public function search(User $user, string $type, string $query, bool $semantic, int $limit, array $filters = []): Collection
    {
        $limit = max(1, min($limit, 50));
        $integrationIds = $user->integrations()->pluck('id')->all();

        return match ($type) {
            'events' => $this->events($integrationIds, $query, $semantic, $limit, $filters),
            'objects' => $this->objects($user, $query, $semantic, $limit, $filters),
            'blocks' => $this->blocks($integrationIds, $query, $semantic, $limit, $filters),
        };
    }

    private function events(array $ids, string $query, bool $semantic, int $limit, array $filters): Collection
    {
        if ($semantic) {
            return Event::hybridSearch($this->embeddings->embed($query), array_filter(['service' => $filters['service'] ?? null, 'domain' => $filters['domain'] ?? null, 'from_date' => $filters['from_date'] ?? null, 'to_date' => $filters['to_date'] ?? null]), threshold: 1.2, limit: $limit)
                ->whereIn('integration_id', $ids)->with(['integration', 'actor', 'target', 'blocks', 'tags'])->get();
        }

        return Event::query()->whereIn('integration_id', $ids)->where(function ($builder) use ($query) {
            $builder->where('action', 'ILIKE', "%{$query}%")->orWhere('service', 'ILIKE', "%{$query}%")->orWhereHas('actor', fn ($q) => $q->where('title', 'ILIKE', "%{$query}%"))->orWhereHas('target', fn ($q) => $q->where('title', 'ILIKE', "%{$query}%"));
        })->when($filters['service'] ?? null, fn ($q, $value) => $q->where('service', $value))->when($filters['domain'] ?? null, fn ($q, $value) => $q->where('domain', $value))->when($filters['from_date'] ?? null, fn ($q, $value) => $q->where('time', '>=', $value))->when($filters['to_date'] ?? null, fn ($q, $value) => $q->where('time', '<=', $value))->with(['integration', 'actor', 'target', 'blocks', 'tags'])->latest('time')->limit($limit)->get();
    }

    private function objects(User $user, string $query, bool $semantic, int $limit, array $filters): Collection
    {
        if ($semantic) {
            return EventObject::hybridSearch($this->embeddings->embed($query), array_filter(['user_id' => $user->id, 'concept' => $filters['concept'] ?? null, 'type' => $filters['object_type'] ?? null]), threshold: 1.2, limit: $limit)->get();
        }

        return EventObject::query()->where('user_id', $user->id)->where(function ($builder) use ($query) {
            $builder->where('title', 'ILIKE', "%{$query}%")->orWhere('content', 'ILIKE', "%{$query}%")->orWhere('concept', 'ILIKE', "%{$query}%")->orWhere('type', 'ILIKE', "%{$query}%");
        })->when($filters['concept'] ?? null, fn ($q, $value) => $q->where('concept', $value))->when($filters['object_type'] ?? null, fn ($q, $value) => $q->where('type', $value))->latest('time')->limit($limit)->get();
    }

    private function blocks(array $ids, string $query, bool $semantic, int $limit, array $filters): Collection
    {
        if ($semantic) {
            return Block::hybridSearch($this->embeddings->embed($query), array_filter(['block_type' => $filters['block_type'] ?? null, 'from_date' => $filters['from_date'] ?? null, 'to_date' => $filters['to_date'] ?? null]), threshold: 1.2, limit: $limit)->whereHas('event', fn ($q) => $q->whereIn('integration_id', $ids))->with('event.integration')->get();
        }

        return Block::query()->whereHas('event', fn ($q) => $q->whereIn('integration_id', $ids))->where(function ($builder) use ($query) {
            $builder->where('title', 'ILIKE', "%{$query}%")->orWhere('block_type', 'ILIKE', "%{$query}%")->orWhereRaw("metadata->>'content' ILIKE ?", ["%{$query}%"]);
        })->when($filters['block_type'] ?? null, fn ($q, $value) => $q->where('block_type', $value))->when($filters['from_date'] ?? null, fn ($q, $value) => $q->where('time', '>=', $value))->when($filters['to_date'] ?? null, fn ($q, $value) => $q->where('time', '<=', $value))->with('event.integration')->latest('time')->limit($limit)->get();
    }
}
