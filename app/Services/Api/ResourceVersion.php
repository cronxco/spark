<?php

namespace App\Services\Api;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Strong, opaque resource versions for optimistic concurrency control.
 *
 * The version deliberately describes the persisted model rather than a JSON
 * representation: fields and eager-loaded relations may change without
 * making a client-held mutation token ambiguous.
 */
class ResourceVersion
{
    public function etag(Model $model): string
    {
        $timestamp = $model->getAttribute('updated_at');
        $version = $timestamp instanceof DateTimeInterface
            ? $timestamp->format('Y-m-d\\TH:i:s.uP')
            : (string) $timestamp;

        return '"' . hash('sha256', $model::class . '|' . $model->getKey() . '|' . $version) . '"';
    }

    public function matches(Model $model, string $ifMatch): bool
    {
        return collect(explode(',', $ifMatch))
            ->map(fn (string $value) => trim($value))
            ->contains($this->etag($model));
    }
}
