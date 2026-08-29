<?php

namespace App\Services\Api;

use Illuminate\Database\Eloquent\Model;

/**
 * Strong, opaque resource versions for optimistic concurrency control.
 *
 * Backed by Postgres's built-in `xmin` system column rather than a
 * timestamp. `updated_at` columns in this app are whole-second precision,
 * so two writes to the same row inside one second would otherwise hash to
 * an identical "version" and silently defeat the If-Match conflict check.
 * `xmin` changes on every row write regardless of timing.
 *
 * Callers don't need to remember to select `xmin` themselves: when it
 * isn't already loaded on the model, it's fetched with one extra
 * indexed-by-key query.
 */
class ResourceVersion
{
    public function etag(Model $model): string
    {
        return '"' . hash('sha256', $model::class . '|' . $model->getKey() . '|' . $this->version($model)) . '"';
    }

    public function matches(Model $model, string $ifMatch): bool
    {
        return collect(explode(',', $ifMatch))
            ->map(fn (string $value) => trim($value))
            ->contains($this->etag($model));
    }

    private function version(Model $model): string
    {
        $xmin = $model->getAttribute('xmin');
        if ($xmin !== null) {
            return (string) $xmin;
        }

        return (string) $model->newQuery()
            ->whereKey($model->getKey())
            ->value($model->qualifyColumn('xmin'));
    }
}
