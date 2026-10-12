<?php

namespace App\Traits;

use App\Models\Event;
use App\Support\PipelineMetadata;
use Illuminate\Support\Arr;

/**
 * Keeps pipeline-owned keys (task executions, embedding info) when an
 * integration sync rewrites the model's metadata, and treats a rewrite that
 * only differs in those keys or in key order as no change at all. Without this
 * every sync marks unchanged models dirty, logs a spurious update and re-runs
 * the pipeline.
 */
trait PreservesPipelineMetadata
{
    public static function bootPreservesPipelineMetadata(): void
    {
        static::saving(function ($model): void {
            if ($model->exists) {
                $model->preservePipelineMetadata();
            }
        });
    }

    protected function preservePipelineMetadata(): void
    {
        $field = $this instanceof Event ? 'event_metadata' : 'metadata';

        if (! $this->isDirty($field)) {
            return;
        }

        $original = $this->getOriginal($field);
        $current = $this->getAttribute($field);

        if (! is_array($original) || ! is_array($current)) {
            return;
        }

        if (PipelineMetadata::areEquivalent($original, $current)) {
            $this->setAttribute($field, $original);

            return;
        }

        $missing = array_diff_key(Arr::only($original, PipelineMetadata::KEYS), $current);

        if ($missing !== []) {
            $this->setAttribute($field, $current + $missing);
        }
    }
}
