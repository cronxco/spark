<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DevicesController extends Controller
{
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
            'apns_token' => ['required', 'string', 'min:32', 'max:200'],
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
