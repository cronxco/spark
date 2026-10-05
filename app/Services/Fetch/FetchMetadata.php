<?php

namespace App\Services\Fetch;

use App\Models\EventObject;
use Illuminate\Support\Facades\DB;

/**
 * Cooperative writes to a bookmark's metadata.
 *
 * Several jobs touch the same bookmark concurrently (the fetch job, the engine
 * manager's history log, list expansion, the enrichment tasks). Writing a
 * whole metadata array read earlier silently discards keys another writer set
 * in the meantime, so every Fetch writer re-reads the row under a lock and
 * applies only its own change.
 */
class FetchMetadata
{
    /**
     * Apply a mutation to the bookmark's current metadata under a row lock.
     *
     * The in-memory model is refreshed with the saved metadata (and any extra
     * attributes) so callers can keep using it.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutation
     * @param  array<string, mixed>  $attributes  Other columns to update in the same write
     */
    public static function mutate(EventObject $bookmark, callable $mutation, array $attributes = []): EventObject
    {
        if (! $bookmark->exists) {
            $bookmark->fill($attributes);
            $bookmark->metadata = $mutation($bookmark->metadata ?? []);

            return $bookmark;
        }

        $saved = DB::transaction(function () use ($bookmark, $mutation, $attributes): EventObject {
            $current = EventObject::query()->lockForUpdate()->find($bookmark->getKey());

            if (! $current) {
                return $bookmark;
            }

            $current->update(array_merge($attributes, [
                'metadata' => $mutation($current->metadata ?? []),
            ]));

            return $current;
        });

        foreach (array_merge(array_keys($attributes), ['metadata', 'updated_at']) as $key) {
            $bookmark->setAttribute($key, $saved->getAttribute($key));
        }
        $bookmark->syncOriginalAttributes(array_merge(array_keys($attributes), ['metadata', 'updated_at']));

        return $bookmark;
    }

    /**
     * Merge keys into the bookmark's current metadata under a row lock.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $attributes
     */
    public static function merge(EventObject $bookmark, array $values, array $attributes = []): EventObject
    {
        return self::mutate(
            $bookmark,
            fn (array $metadata): array => array_merge($metadata, $values),
            $attributes,
        );
    }
}
