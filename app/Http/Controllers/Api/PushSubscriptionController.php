<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Notifications\TestPushNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PushSubscriptionController extends Controller
{
    /**
     * Get the VAPID public key for client-side subscription
     */
    public function vapidPublicKey(): JsonResponse
    {
        return response()->json([
            'publicKey' => config('webpush.vapid.public_key'),
        ]);
    }

    /**
     * Store a new push subscription
     */
    public function subscribe(Request $request): JsonResponse
    {
        $request->validate([
            'endpoint' => 'required|url|max:500',
            'keys.p256dh' => 'required|string',
            'keys.auth' => 'required|string',
        ]);

        $user = Auth::user();

        // Check if subscription already exists
        $existingSubscription = $user->pushSubscriptions()
            ->where('endpoint', $request->endpoint)
            ->first();

        if ($existingSubscription) {
            // Update existing subscription
            $existingSubscription->update([
                'public_key' => $request->input('keys.p256dh'),
                'auth_token' => $request->input('keys.auth'),
                'content_encoding' => $request->input('contentEncoding', 'aesgcm'),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Subscription updated',
            ]);
        }

        // Create new subscription
        $user->updatePushSubscription(
            $request->endpoint,
            $request->input('keys.p256dh'),
            $request->input('keys.auth'),
            $request->input('contentEncoding', 'aesgcm')
        );

        return response()->json([
            'success' => true,
            'message' => 'Subscription created',
        ], 201);
    }

    /**
     * Remove a push subscription
     */
    public function unsubscribe(Request $request): JsonResponse
    {
        $request->validate([
            'endpoint' => 'required|url',
        ]);

        $user = Auth::user();

        $deleted = $user->pushSubscriptions()
            ->where('endpoint', $request->endpoint)
            ->delete();

        if ($deleted) {
            return response()->json([
                'success' => true,
                'message' => 'Subscription removed',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Subscription not found',
        ], 404);
    }

    /**
     * Check if the current browser is subscribed
     */
    public function status(Request $request): JsonResponse
    {
        $endpoint = $request->query('endpoint');

        if (! $endpoint) {
            return response()->json([
                'subscribed' => false,
                'subscriptionCount' => Auth::user()->pushSubscriptions()->count(),
            ]);
        }

        $user = Auth::user();
        $subscription = $user->pushSubscriptions()
            ->where('endpoint', $endpoint)
            ->exists();

        return response()->json([
            'subscribed' => $subscription,
            'subscriptionCount' => $user->pushSubscriptions()->count(),
        ]);
    }

    /**
     * List all subscriptions for the current user
     */
    public function list(): JsonResponse
    {
        $user = Auth::user();

        $subscriptions = $user->pushSubscriptions()
            ->select('id', 'endpoint', 'created_at', 'updated_at')
            ->get()
            ->map(function ($subscription) {
                // Extract browser/device info from endpoint
                $endpoint = $subscription->endpoint;
                $browser = 'Unknown';

                if (str_contains($endpoint, 'fcm.googleapis.com')) {
                    $browser = 'Chrome/Android';
                } elseif (str_contains($endpoint, 'mozilla.com')) {
                    $browser = 'Firefox';
                } elseif (str_contains($endpoint, 'windows.com')) {
                    $browser = 'Edge';
                } elseif (str_contains($endpoint, 'apple.com') || str_contains($endpoint, 'push.apple')) {
                    $browser = 'Safari/iOS';
                }

                return [
                    'id' => $subscription->id,
                    'browser' => $browser,
                    'created_at' => $subscription->created_at->toIso8601String(),
                    'updated_at' => $subscription->updated_at->toIso8601String(),
                ];
            });

        return response()->json([
            'subscriptions' => $subscriptions,
        ]);
    }

    /**
     * Remove a specific subscription by ID
     */
    public function destroy(int $id): JsonResponse
    {
        $user = Auth::user();

        $deleted = $user->pushSubscriptions()
            ->where('id', $id)
            ->delete();

        if ($deleted) {
            return response()->json([
                'success' => true,
                'message' => 'Subscription removed',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Subscription not found',
        ], 404);
    }

    /**
     * Send a test push notification
     */
    public function test(): JsonResponse
    {
        $user = Auth::user();

        if (! $user->pushSubscriptions()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'No push subscriptions found',
            ], 400);
        }

        // Send a test notification
        $user->notify(new TestPushNotification);

        return response()->json([
            'success' => true,
            'message' => 'Test notification sent',
        ]);
    }
}
