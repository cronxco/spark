<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SearchLog extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'query',
        'type',
        'source',
        'results_count',
        'events_count',
        'blocks_count',
        'avg_similarity',
        'top_similarity',
        'threshold',
        'response_time_ms',
        'filters',
        'clicked',
        'clicked_result_id',
        'clicked_result_type',
    ];

    protected $casts = [
        'filters' => 'array',
        'avg_similarity' => 'float',
        'top_similarity' => 'float',
        'threshold' => 'float',
        'clicked' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get popular queries for a user or all users
     */
    public static function getPopularQueries(?int $userId = null, int $limit = 10, int $days = 30)
    {
        $query = static::query()
            ->selectRaw('query, COUNT(*) as count, AVG(avg_similarity) as avg_similarity, AVG(results_count) as avg_results')
            ->where('created_at', '>=', now()->subDays($days))
            ->groupBy('query')
            ->orderByDesc('count')
            ->limit($limit);

        if ($userId) {
            $query->where('user_id', $userId);
        }

        return $query->get();
    }

    /**
     * Get queries with zero results
     */
    public static function getZeroResultQueries(?int $userId = null, int $limit = 10, int $days = 30)
    {
        $query = static::query()
            ->selectRaw('query, COUNT(*) as count')
            ->where('created_at', '>=', now()->subDays($days))
            ->where('results_count', 0)
            ->groupBy('query')
            ->orderByDesc('count')
            ->limit($limit);

        if ($userId) {
            $query->where('user_id', $userId);
        }

        return $query->get();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
