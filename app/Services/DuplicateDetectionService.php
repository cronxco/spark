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
     * @param  string  $userId  User ID (UUID) to scope the search
     * @param  float  $similarityThreshold  Similarity threshold (0-1), default 0.95 for 95% match
     * @param  int  $limit  Maximum number of duplicate pairs to return
     * @return Collection Collection of duplicate pairs with similarity scores
     */
    public function findDuplicateEvents(string $userId, float $similarityThreshold = 0.95, int $limit = 100): Collection
    {
        return $this->findDuplicates(Event::class, $userId, $similarityThreshold, $limit);
    }

    /**
     * Find potential duplicate blocks using semantic similarity
     *
     * @param  string  $userId  User ID (UUID) to scope the search
     * @param  float  $similarityThreshold  Similarity threshold (0-1), default 0.95 for 95% match
     * @param  int  $limit  Maximum number of duplicate pairs to return
     * @return Collection Collection of duplicate pairs with similarity scores
     */
    public function findDuplicateBlocks(string $userId, float $similarityThreshold = 0.95, int $limit = 100): Collection
    {
        return $this->findDuplicates(Block::class, $userId, $similarityThreshold, $limit);
    }

    /**
     * Find potential duplicate objects using semantic similarity
     *
     * @param  string  $userId  User ID (UUID) to scope the search
     * @param  float  $similarityThreshold  Similarity threshold (0-1), default 0.95 for 95% match
     * @param  int  $limit  Maximum number of duplicate pairs to return
     * @return Collection Collection of duplicate pairs with similarity scores
     */
    public function findDuplicateObjects(string $userId, float $similarityThreshold = 0.95, int $limit = 100): Collection
    {
        return $this->findDuplicates(EventObject::class, $userId, $similarityThreshold, $limit);
    }

    /**
     * Generic duplicate finder using semantic similarity
     *
     * @param  string  $modelClass  Model class to search
     * @param  string  $userId  User ID (UUID) to scope the search
     * @param  float  $similarityThreshold  Similarity threshold (0-1)
     * @param  int  $limit  Maximum number of duplicate pairs to return
     * @return Collection Collection of duplicate pairs with similarity scores
     */
    private function findDuplicates(string $modelClass, string $userId, float $similarityThreshold, int $limit): Collection
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

        // Apply user filter based on model type
        if ($modelClass === EventObject::class) {
            $query->where('t1.user_id', $userId)
                ->where('t2.user_id', $userId);
        } else {
            // For Event and Block, filter by integration user_id
            $query->join('integrations as i1', 't1.integration_id', '=', 'i1.id')
                ->join('integrations as i2', 't2.integration_id', '=', 'i2.id')
                ->where('i1.user_id', $userId)
                ->where('i2.user_id', $userId);
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

        // Load the actual models with relationships
        $pairs = $pairs->map(function ($pair) use ($modelClass) {
            $model1 = $modelClass::with($this->getRelationships($modelClass))->find($pair->id1);
            $model2 = $modelClass::with($this->getRelationships($modelClass))->find($pair->id2);

            return [
                'model1' => $model1,
                'model2' => $model2,
                'similarity' => round($pair->similarity, 4),
            ];
        })->filter(function ($pair) {
            // Filter out any null models (deleted during query)
            return $pair['model1'] !== null && $pair['model2'] !== null;
        });

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
