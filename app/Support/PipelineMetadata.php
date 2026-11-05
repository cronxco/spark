<?php

namespace App\Support;

use Illuminate\Support\Arr;

/**
 * Helpers for the metadata keys the task pipeline writes into a model's own
 * JSON metadata column, and for comparing metadata regardless of key order.
 */
class PipelineMetadata
{
    /**
     * Keys owned by the pipeline. Syncs rewrite metadata from scratch and drop
     * them, which is not a change to the model's data.
     *
     * @var array<int, string>
     */
    public const KEYS = [
        'task_executions',
        'embedding_model',
        'embedding_dimensions',
        'embedding_generated_at',
    ];

    /**
     * @param  array<int|string, mixed>  $before
     * @param  array<int|string, mixed>  $after
     */
    public static function areEquivalent(array $before, array $after): bool
    {
        return self::normalize(Arr::except($before, self::KEYS))
            === self::normalize(Arr::except($after, self::KEYS));
    }

    /**
     * Sort object keys recursively; list order is meaningful and kept.
     *
     * @param  array<int|string, mixed>  $value
     * @return array<int|string, mixed>
     */
    public static function normalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::normalize($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
