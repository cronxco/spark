<?php

namespace App\Http\Middleware;

use App\Models\Relationship;
use App\Services\Api\EntityMutationService;
use App\Services\Api\ResourceVersion;
use App\Services\Mobile\EventLookup;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Require a current strong model ETag before an owned resource is changed. */
class RequireIfMatch
{
    public function __construct(
        private EntityMutationService $entities,
        private EventLookup $events,
        private ResourceVersion $versions,
    ) {}

    public function handle(Request $request, Closure $next, string $target): Response
    {
        $model = match ($target) {
            'entity' => $this->entities->find($request->user(), rtrim((string) $request->route('kind'), 's'), (string) $request->route('id')),
            'event' => $this->events->find($request->user(), (string) $request->route('id')),
            'object' => $this->entities->find($request->user(), 'object', (string) $request->route('id')),
            'relationship' => Relationship::query()->where('user_id', $request->user()->id)->find($request->route('relationship')),
            default => null,
        };

        // Preserve the endpoint's established ownership/not-found response.
        if (! $model) {
            return $next($request);
        }

        $etag = $this->versions->etag($model);
        $ifMatch = $request->header('If-Match');
        if (! $ifMatch) {
            return $this->preconditionResponse('An If-Match header is required.', 428, $etag);
        }

        if (! $this->versions->matches($model, $ifMatch)) {
            return $this->preconditionResponse('The resource has changed. Refresh it and retry.', 412, $etag);
        }

        return $next($request);
    }

    private function preconditionResponse(string $message, int $status, string $etag): JsonResponse
    {
        return response()->json(['message' => $message, 'etag' => $etag], $status)->header('ETag', $etag);
    }
}
