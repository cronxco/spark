<?php

namespace App\Services;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DuplicateDetectionService
{
    /**
     * Find potential duplicate events using semantic similarity
     *
     * @param  string|null  $userId  User ID (UUID) to scope the search; null searches within each user across all users
     * @param  float  $similarityThreshold  Similarity threshold (0-1), default 0.95 for 95% match
     * @param  int  $limit  Maximum number of duplicate pairs to return
     * @return Collection Collection of duplicate pairs with similarity scores
     */
    public function findDuplicateEvents(?string $userId, float $similarityThreshold = 0.95, int $limit = 100): Collection
    {
        return $this->findDuplicates(Event::class, $userId, $similarityThreshold, $limit);
    }

    /**
     * Find potential duplicate blocks using semantic similarity
     *
     * @param  string|null  $userId  User ID (UUID) to scope the search; null searches within each user across all users
     * @param  float  $similarityThreshold  Similarity threshold (0-1), default 0.95 for 95% match
     * @param  int  $limit  Maximum number of duplicate pairs to return
     * @return Collection Collection of duplicate pairs with similarity scores
     */
    public function findDuplicateBlocks(?string $userId, float $similarityThreshold = 0.95, int $limit = 100): Collection
    {
        return $this->findDuplicates(Block::class, $userId, $similarityThreshold, $limit);
    }

    /**
     * Find potential duplicate objects using semantic similarity
     *
     * @param  string|null  $userId  User ID (UUID) to scope the search; null searches within each user across all users
     * @param  float  $similarityThreshold  Similarity threshold (0-1), default 0.95 for 95% match
     * @param  int  $limit  Maximum number of duplicate pairs to return
     * @return Collection Collection of duplicate pairs with similarity scores
     */
    public function findDuplicateObjects(?string $userId, float $similarityThreshold = 0.95, int $limit = 100): Collection
    {
        return $this->findDuplicates(EventObject::class, $userId, $similarityThreshold, $limit);
    }

    /**
     * Generic duplicate finder using semantic similarity
     *
     * @param  string  $modelClass  Model class to search
     * @param  string|null  $userId  User ID (UUID) to scope the search; null searches within each user across all users
     * @param  float  $similarityThreshold  Similarity threshold (0-1)
     * @param  int  $limit  Maximum number of duplicate pairs to return
     * @return Collection Collection of duplicate pairs with similarity scores
     */
    private function findDuplicates(string $modelClass, ?string $userId, float $similarityThreshold, int $limit): Collection
    {
        // Determine table name and user filter
        $table = match ($modelClass) {
            Event::class => 'events',
            Block::class => 'blocks',
            EventObject::class => 'event_objects',
        };

        // Build query to find pairs with high similarity
        // We use a self-join to find pairs and calculate similarity
        $query = DB::table($table . ' as t1')
            ->join($table . ' as t2', function ($join) {
                $join->on('t1.id', '<', 't2.id') // Avoid duplicate pairs (a,b) and (b,a)
                    ->whereNotNull('t1.embeddings')
                    ->whereNotNull('t2.embeddings');
            });

        $query->whereNull('t1.deleted_at')->whereNull('t2.deleted_at');

        // Apply user filter based on model type
        if ($modelClass === EventObject::class) {
            $query->whereColumn('t1.user_id', 't2.user_id')
                ->when($userId !== null, fn ($q) => $q->where('t1.user_id', $userId));
        } elseif ($modelClass === Block::class) {
            // Blocks belong to an integration through their event
            $query->join('events as e1', 't1.event_id', '=', 'e1.id')
                ->join('events as e2', 't2.event_id', '=', 'e2.id')
                ->join('integrations as i1', 'e1.integration_id', '=', 'i1.id')
                ->join('integrations as i2', 'e2.integration_id', '=', 'i2.id')
                ->whereColumn('i1.user_id', 'i2.user_id')
                ->when($userId !== null, fn ($q) => $q->where('i1.user_id', $userId));
        } else {
            $query->join('integrations as i1', 't1.integration_id', '=', 'i1.id')
                ->join('integrations as i2', 't2.integration_id', '=', 'i2.id')
                ->whereColumn('i1.user_id', 'i2.user_id')
                ->when($userId !== null, fn ($q) => $q->where('i1.user_id', $userId));
        }

        // Calculate similarity using PostgreSQL vector distance
        // cosine distance in pgvector is 1 - cosine similarity
        // So we need similarity > threshold => 1 - distance > threshold => distance < 1 - threshold
        $distanceThreshold = 1 - $similarityThreshold;

        $query->selectRaw('
            t1.id as id1,
            t2.id as id2,
            1 - (t1.embeddings <=> t2.embeddings) as similarity
        ')
            ->whereRaw('(t1.embeddings <=> t2.embeddings) < ?', [$distanceThreshold])
            ->orderByRaw('(t1.embeddings <=> t2.embeddings) ASC')
            ->limit($limit);

        $pairs = $query->get();

        // Load both sides of every pair in one query
        $models = $modelClass::with($this->getRelationships($modelClass))
            ->whereIn('id', $pairs->pluck('id1')->merge($pairs->pluck('id2'))->unique()->all())
            ->get()
            ->keyBy('id');

        $pairs = $pairs->map(fn ($pair) => [
            'model1' => $models->get($pair->id1),
            'model2' => $models->get($pair->id2),
            'similarity' => round($pair->similarity, 4),
        ])->filter(function ($pair) {
            // Filter out any null models (deleted during query)
            return $pair['model1'] !== null && $pair['model2'] !== null;
        })->values();

        return $pairs;
    }

    /**
     * Get relationships to eager load for each model type
     */
    private function getRelationships(string $modelClass): array
    {
        return match ($modelClass) {
            Event::class => ['actor', 'target', 'integration'],
            Block::class => ['event.integration'],
            EventObject::class => [],
        };
    }
}
