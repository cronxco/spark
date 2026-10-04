<?php

namespace App\Services\Search;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The search ranking shared by Spotlight and the mobile search API, with
 * recency as one tunable signal.
 *
 * Every result gets a relevance in [0, 1] (how well it matches) and a recency
 * in [0, 1] (1 for now, halving every `half_life_days`). They are blended as
 * `(1 - weight) * relevance + weight * recency` and results are ordered by
 * that score. A weight of 0 disables recency entirely: results are ordered
 * by relevance alone, with the id as a stable tie-break.
 *
 * Text relevance: an exact match on the primary column scores 1.0, a prefix
 * match 0.8, a match anywhere in it 0.6, and a row that matched on some other
 * column 0.4. Semantic relevance is the cosine similarity `1 - distance / 2`.
 */
class RecencyRanking
{
    public const EXACT = 1.0;

    public const PREFIX = 0.8;

    public const CONTAINS = 0.6;

    public const OTHER_FIELD = 0.4;

    protected float $weight;

    protected float $halfLifeDays;

    public function __construct(?float $weight = null, ?float $halfLifeDays = null)
    {
        $weight ??= (float) config('spark.search.recency.weight', 0.2);
        $halfLifeDays ??= (float) config('spark.search.recency.half_life_days', 30);

        $this->weight = max(0.0, min(1.0, $weight));
        $this->halfLifeDays = max(0.01, $halfLifeDays);
    }

    /**
     * A copy of this ranking with a different weight (and optionally half-life).
     */
    public function withWeight(float $weight, ?float $halfLifeDays = null): self
    {
        return new self($weight, $halfLifeDays ?? $this->halfLifeDays);
    }

    public function weight(): float
    {
        return $this->weight;
    }

    public function halfLifeDays(): float
    {
        return $this->halfLifeDays;
    }

    public function enabled(): bool
    {
        return $this->weight > 0;
    }

    /**
     * Recency in [0, 1]: 1 for now (or the future), 0.5 at one half-life, 0 without a time.
     */
    public function recency(?CarbonInterface $time, ?CarbonInterface $now = null): float
    {
        if ($time === null) {
            return 0.0;
        }

        $now ??= now();
        $ageDays = max(0, $now->getTimestamp() - $time->getTimestamp()) / 86400;

        return 0.5 ** ($ageDays / $this->halfLifeDays);
    }

    /**
     * The blended score for a result with the given relevance and time.
     */
    public function score(float $relevance, ?CarbonInterface $time, ?CarbonInterface $now = null): float
    {
        if (! $this->enabled()) {
            return $relevance;
        }

        return (1 - $this->weight) * $relevance + $this->weight * $this->recency($time, $now);
    }

    /**
     * Text relevance of a value against the search term, mirroring the SQL.
     */
    public function textRelevance(?string $value, string $term): float
    {
        $value = mb_strtolower((string) $value);
        $term = mb_strtolower(trim($term));

        return match (true) {
            $term === '' => self::OTHER_FIELD,
            $value === $term => self::EXACT,
            str_starts_with($value, $term) => self::PREFIX,
            str_contains($value, $term) => self::CONTAINS,
            default => self::OTHER_FIELD,
        };
    }

    /**
     * Order a text (ILIKE) search by relevance on `$primaryColumn` blended with recency.
     *
     * Replaces any existing ordering on the query.
     */
    public function orderByText(Builder $query, string $term, string $primaryColumn, string $timeColumn = 'time'): Builder
    {
        $model = $query->getModel();
        $column = $model->qualifyColumn($primaryColumn);
        $term = trim($term);
        $escaped = addcslashes($term, '\\%_');

        $relevanceSql = sprintf(
            'CASE WHEN LOWER(%1$s) = LOWER(?) THEN %2$F WHEN %1$s ILIKE ? THEN %3$F WHEN %1$s ILIKE ? THEN %4$F ELSE %5$F END',
            $column,
            self::EXACT,
            self::PREFIX,
            self::CONTAINS,
            self::OTHER_FIELD,
        );

        return $this->applyOrder($query, $relevanceSql, [$term, $escaped . '%', '%' . $escaped . '%'], $timeColumn);
    }

    /**
     * Order a semantic (pgvector) search by similarity blended with recency.
     *
     * Use on a query from a `semanticSearch`/`hybridSearch` scope called with
     * `temporalWeight: 0`; this replaces that scope's ordering and adds a
     * `days_ago` column for display.
     *
     * @param  array<int, float>  $embedding
     */
    public function orderBySemantic(Builder $query, array $embedding, string $timeColumn = 'time'): Builder
    {
        $model = $query->getModel();
        $embeddingString = '[' . implode(',', $embedding) . ']';
        $relevanceSql = sprintf('GREATEST(0, 1 - (%s <=> ?) / 2)', $model->qualifyColumn('embeddings'));

        $query->selectRaw(sprintf(
            '(%d - EXTRACT(EPOCH FROM %s)) / 86400.0 as days_ago',
            now()->getTimestamp(),
            $model->qualifyColumn($timeColumn),
        ));

        return $this->applyOrder($query, $relevanceSql, [$embeddingString], $timeColumn);
    }

    /**
     * @param  array<int, mixed>  $relevanceBindings
     */
    protected function applyOrder(Builder $query, string $relevanceSql, array $relevanceBindings, string $timeColumn): Builder
    {
        $model = $query->getModel();
        $query->reorder();

        if (! $this->enabled()) {
            return $query
                ->orderByRaw("({$relevanceSql}) DESC", $relevanceBindings)
                ->orderBy($model->getQualifiedKeyName());
        }

        $recencySql = sprintf(
            'COALESCE(POWER(0.5, GREATEST((%d - EXTRACT(EPOCH FROM %s)) / 86400.0, 0) / %F), 0)',
            now()->getTimestamp(),
            $model->qualifyColumn($timeColumn),
            $this->halfLifeDays,
        );

        return $query
            ->orderByRaw(
                sprintf('(%F * (%s) + %F * %s) DESC', 1 - $this->weight, $relevanceSql, $this->weight, $recencySql),
                $relevanceBindings,
            )
            ->orderBy($model->getQualifiedKeyName());
    }
}
