<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Events\Mobile\LiveActivityUpdate;
use App\Http\Controllers\Controller;
use App\Models\LiveActivityToken;
use App\Services\ApnsLiveActivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class LiveActivitiesController extends Controller
{
    protected const RATE_LIMIT_PER_HOUR = 16;

    public function __construct(protected ApnsLiveActivityService $apns) {}

    /**
     * POST /live-activities
     *
     * Creates the token row and fires the initial APN update. The iOS client
     * is the source of truth for the activity UUID and push token — we just
     * persist them.
     */
    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'activity_id' => ['required', 'uuid'],
            'activity_type' => ['required', 'string', 'max:60'],
            'push_token' => ['required', 'string', 'min:16'],
            'device_id' => ['nullable', 'integer'],
            'content_state' => ['nullable', 'array'],
        ]);

        $token = LiveActivityToken::updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'activity_id' => $validated['activity_id'],
            ],
            [
                'activity_type' => $validated['activity_type'],
                'push_token' => $validated['push_token'],
                'device_id' => $validated['device_id'] ?? null,
                'starts_at' => now(),
                'ends_at' => null,
            ],
        );

        $state = $validated['content_state'] ?? [];
        $this->dispatchPush($token, 'start', $state);

        return response()->json($this->resource($token), 201);
    }

    /**
     * PATCH /live-activities/{id}
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $token = $this->findOrFail($request, $id);

        $validated = $request->validate([
            'content_state' => ['required', 'array'],
            'alert' => ['nullable', 'array'],
        ]);

        if (! $this->allowPush($token)) {
            return response()->json(['message' => 'rate limit exceeded'], 429);
        }

        $this->dispatchPush($token, 'update', $validated['content_state'], $validated['alert'] ?? []);

        return response()->json($this->resource($token));
    }

    /**
     * DELETE /live-activities/{id}
     */
    public function end(Request $request, string $id): JsonResponse
    {
        $token = $this->findOrFail($request, $id);

        $this->dispatchPush($token, 'end', []);

        $token->forceFill(['ends_at' => now()])->save();

        return response()->json(null, 204);
    }

    /**
     * POST /live-activities/{id}/tokens
     *
     * iOS rotates the push-token mid-activity. Accept and persist.
     */
    public function registerToken(Request $request, string $id): JsonResponse
    {
        $token = $this->findOrFail($request, $id);

        $validated = $request->validate([
            'push_token' => ['required', 'string', 'min:16'],
        ]);

        $token->forceFill(['push_token' => $validated['push_token']])->save();

        return response()->json($this->resource($token));
    }

    protected function findOrFail(Request $request, string $id): LiveActivityToken
    {
        $token = LiveActivityToken::where('user_id', $request->user()->id)
            ->where('activity_id', $id)
            ->first();

        abort_if($token === null, 404, 'Activity not found.');

        return $token;
    }

    protected function allowPush(LiveActivityToken $token): bool
    {
        $key = 'la:push:' . $token->user_id . ':' . $token->activity_id;

        if (RateLimiter::tooManyAttempts($key, self::RATE_LIMIT_PER_HOUR)) {
            return false;
        }

        RateLimiter::hit($key, 3600);

        return true;
    }

    protected function dispatchPush(LiveActivityToken $token, string $event, array $state, array $alert = []): void
    {
        if ($event === 'end') {
            $this->apns->end($token, $state);
        } else {
            $this->apns->startOrUpdate($token, $state, $alert);
        }

        event(new LiveActivityUpdate(
            userId: (string) $token->user_id,
            activityId: $token->activity_id,
            activityType: $token->activity_type,
            event: $event,
            state: $state,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    protected function resource(LiveActivityToken $token): array
    {
        return [
            'id' => $token->id,
            'activity_id' => $token->activity_id,
            'activity_type' => $token->activity_type,
            'starts_at' => optional($token->starts_at)->toIso8601String(),
            'ends_at' => optional($token->ends_at)->toIso8601String(),
            'last_pushed_at' => optional($token->last_pushed_at)->toIso8601String(),
        ];
    }
}
