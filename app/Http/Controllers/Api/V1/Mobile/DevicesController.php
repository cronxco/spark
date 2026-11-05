<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Notifications\TestPushNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DevicesController extends Controller
{
    /**
     * GET /api/v1/mobile/devices
     *
     * Returns all iOS push subscriptions registered for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $devices = $request->user()
            ->pushSubscriptions()
            ->apns()
            ->get()
            ->map(fn (PushSubscription $sub) => [
                'id' => $sub->id,
                // Fields consumed by the iOS RegisteredDevice model. `name` and
                // `platform` are required by the client decoder, so they must
                // always be present and non-null.
                'name' => $sub->device_name ?: 'iPhone',
                'platform' => $sub->device_type,
                'last_seen_at' => $sub->updated_at?->toIso8601String(),
                'is_current_device' => false,
                // Richer fields retained for web/admin consumers.
                'device_type' => $sub->device_type,
                'endpoint' => $sub->endpoint,
                'app_environment' => $sub->app_environment,
                'bundle_id' => $sub->bundle_id,
                'app_version' => $sub->app_version,
                'os_version' => $sub->os_version,
                'created_at' => $sub->created_at?->toIso8601String(),
                'updated_at' => $sub->updated_at?->toIso8601String(),
            ]);

        return response()->json(['devices' => $devices]);
    }

    /**
     * POST /api/v1/mobile/devices
     *
     * Upserts a PushSubscription keyed on `(user_id, endpoint)` where the
     * endpoint is the APNs device token. device_type is pinned to 'ios' so the
     * ApnsChannel::scopeApns() query picks it up.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'apns_token' => ['required', 'string', 'size:64', 'regex:/^[0-9a-fA-F]{64}$/'],
            'app_environment' => ['required', 'string', 'in:sandbox,production'],
            'bundle_id' => ['required', 'string', 'max:100'],
            'app_version' => ['required', 'string', 'max:30'],
            'os_version' => ['required', 'string', 'max:30'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $request->user();

        $subscription = PushSubscription::query()
            ->updateOrCreate(
                ['endpoint' => $validated['apns_token']],
                [
                    'subscribable_type' => get_class($user),
                    'subscribable_id' => $user->getKey(),
                    'device_type' => PushSubscription::DEVICE_TYPE_IOS,
                    'app_environment' => $validated['app_environment'],
                    'bundle_id' => $validated['bundle_id'],
                    'app_version' => $validated['app_version'],
                    'os_version' => $validated['os_version'],
                    'device_name' => $validated['device_name'] ?? null,
                ],
            );

        return response()->json([
            'id' => $subscription->id,
            'device_type' => $subscription->device_type,
            'endpoint' => $subscription->endpoint,
            'app_environment' => $subscription->app_environment,
        ], 201);
    }

    /**
     * DELETE /api/v1/mobile/devices/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $subscription = $request->user()->pushSubscriptions()->find($id);

        if (! $subscription) {
            return response()->json(['message' => 'Device not found.'], 404);
        }

        $subscription->delete();

        return response()->json(null, 204);
    }

    /**
     * POST /api/v1/mobile/devices/test
     */
    public function test(Request $request): JsonResponse
    {
        if (! $request->user()->pushSubscriptions()->apns()->exists()) {
            return response()->json(['message' => 'No iOS push subscriptions registered.'], 400);
        }

        $request->user()->notify(new TestPushNotification('ios'));

        return response()->json(null, 204);
    }
}
