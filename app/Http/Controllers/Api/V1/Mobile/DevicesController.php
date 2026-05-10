<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
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

        $subscription = $request->user()->pushSubscriptions()
            ->updateOrCreate(
                ['endpoint' => $validated['apns_token']],
                [
                    'device_type' => PushSubscription::DEVICE_TYPE_IOS,
                    'app_environment' => $validated['app_environment'],
                    'bundle_id' => $validated['bundle_id'],
                    'app_version' => $validated['app_version'],
                    'os_version' => $validated['os_version'],
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
}
