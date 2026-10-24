<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\Compact\CompactEventResource;
use App\Http\Resources\Compact\CompactObjectResource;
use App\Services\Api\ResourceVersion;
use App\Services\EffectiveTimezoneResolver;
use App\Services\Mobile\ObjectLookup;
use App\Services\PersonProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use stdClass;

class ObjectsController extends Controller
{
    public function __construct(protected ObjectLookup $lookup, protected ResourceVersion $versions) {}

    /**
     * GET /api/v1/mobile/objects/{id}
     *
     * `include_events` (bool, default true) — attach recent events.
     * `event_limit` (int, 1..25) — cap on recent events.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $object = $this->lookup->find($user, $id);

        if (! $object) {
            return response()->json(['message' => 'Object not found.'], 404);
        }

        $payload = (new CompactObjectResource($object))->resolve($request);
        $lastModified = $object->updated_at;

        if ($request->boolean('include_events', true)) {
            $limit = max(1, min(ObjectLookup::EVENT_LIMIT_MAX, (int) $request->query('event_limit', ObjectLookup::EVENT_LIMIT_DEFAULT)));
            $events = $this->lookup->recentEvents($object, $user, $limit);
            $payload['recent_events'] = CompactEventResource::collection($events)->resolve($request);

            $eventMax = $events->max('updated_at');
            if ($eventMax && (! $lastModified || $eventMax > $lastModified)) {
                $lastModified = $eventMax;
            }
        }

        $response = response()->json($payload);

        if ($lastModified) {
            $response->header('Last-Modified', $lastModified->toRfc7231String());
        }
        $response->header('ETag', $this->versions->etag($object));

        return $response;
    }

    /**
     * GET /api/v1/mobile/people/{id}/timeline
     *
     * A person's timeline: events they act in, are tagged on with `with`, or
     * are @mentioned in. `month` (Y-m, default this month) sets the calendar
     * grid, a count of events per local day. `date` (Y-m-d) lists that day's
     * events; without it the latest `limit` (1..50) come back.
     */
    public function timeline(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $person = $this->lookup->find($user, $id);

        if (! $person || $person->concept !== 'person') {
            return response()->json(['message' => 'Person not found.'], 404);
        }

        $validated = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:' . PersonProfileService::EVENT_LIMIT],
        ]);

        $timezone = app(EffectiveTimezoneResolver::class)->timezoneFor($user);
        $month = Carbon::createFromFormat('!Y-m', $validated['month'] ?? Carbon::now($timezone)->format('Y-m'), $timezone);
        $limit = (int) ($validated['limit'] ?? 25);
        $service = app(PersonProfileService::class);

        $events = isset($validated['date'])
            ? $service->eventsOn($person, Carbon::createFromFormat('Y-m-d', $validated['date'], $timezone), $timezone, $limit)
            : $service->events($person, $limit);

        return response()->json([
            'person' => (new CompactObjectResource($person))->resolve($request),
            'event_count' => $service->eventCount($person),
            'month' => $month->format('Y-m'),
            'calendar' => $service->calendar($person, $month, $timezone) ?: new stdClass,
            'events' => CompactEventResource::collection($events->load(['blocks']))->resolve($request),
        ]);
    }

    /**
     * DELETE /api/v1/mobile/objects/{id}
     *
     * Soft-deletes an owned object. Its events are kept. The row stays
     * recoverable through `POST /objects/{id}/restore`.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $object = $this->lookup->find($request->user(), $id);

        if (! $object) {
            return response()->json(['message' => 'Object not found.'], 404);
        }

        $object->delete();

        return response()->json([
            'id' => $object->id,
            'deleted_at' => $object->deleted_at?->toIso8601String(),
        ]);
    }

    /**
     * POST /api/v1/mobile/objects/{id}/restore
     *
     * Restores a soft-deleted owned object and returns it without recent
     * events. An object that is not deleted is returned unchanged.
     */
    public function restore(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $trashed = $this->lookup->findTrashed($user, $id);
        $trashed?->restore();

        $object = $this->lookup->find($user, $id);

        if (! $object) {
            return response()->json(['message' => 'Object not found.'], 404);
        }

        return response()->json(
            (new CompactObjectResource($object))->resolve($request),
        )->header('ETag', $this->versions->etag($object));
    }
}
