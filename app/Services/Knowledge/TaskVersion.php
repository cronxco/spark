<?php

namespace App\Services\Knowledge;

use App\Services\Api\ResourceVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;

final class TaskVersion
{
    public function __construct(private ResourceVersion $versions) {}

    public function assert(Model $model, ?string $version): void
    {
        if (! $version || ! $this->versions->matches($model, $version)) {
            $etag = $this->versions->etag($model);
            throw new HttpResponseException(response()->json(['message' => $version ? 'This changed. Refresh and review your edit.' : 'An If-Match header is required.', 'version' => $etag], $version ? 412 : 428)->header('ETag', $etag));
        }
    }
}
