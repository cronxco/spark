<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Notifications\NotificationCatalogue;
use App\Services\Api\ResourceVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NotificationPreferencesController extends Controller
{
    public function __construct(private ResourceVersion $versions) {}

    /**
     * GET /api/v1/mobile/settings/notifications
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->mobilePayload($request->user()->settings['notifications'] ?? []))
            ->header('ETag', $this->versions->etag($request->user()));
    }

    /**
     * PATCH /api/v1/mobile/settings/notifications
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'categories' => ['sometimes', 'array'],
            'categories.*' => ['boolean'],
            'delivery_mode' => ['required', Rule::in(['immediate', 'work_hours', 'daily_digest'])],
            'digest_time' => ['nullable', 'date_format:H:i'],
        ]);

        $categories = $validated['categories'] ?? [];
        $categories = array_intersect_key($categories, array_flip($this->categories()));

        $request->user()->updateNotificationPreferences([
            'push_types' => $categories,
            'delayed_sending' => [
                'mode' => $validated['delivery_mode'],
                'digest_time' => $validated['digest_time'] ?? '08:00',
            ],
        ]);

        return response()->json(null, 204);
    }

    /**
     * The notification types a user may switch on and off.
     *
     * Derived from NotificationCatalogue rather than hand-listed: the five
     * categories this used to name were invented for the mobile API and four of
     * them gated notifications that are never sent, while three real types had
     * no toggle at all. SparkNotification::via() gates on
     * hasPushNotificationsEnabledForType(), which is keyed by the real type
     * string, so these keys have to be those same strings to have any effect.
     *
     * @return array<int, string>
     */
    private function categories(): array
    {
        return NotificationCatalogue::configurableTypes();
    }

    /**
     * @param  array<string, mixed>  $preferences
     * @return array<string, mixed>
     */
    private function mobilePayload(array $preferences): array
    {
        $pushTypes = $preferences['push_types'] ?? [];
        $delayed = $preferences['delayed_sending'] ?? [];

        return [
            'categories' => collect($this->categories())
                ->mapWithKeys(fn (string $category) => [$category => $pushTypes[$category] ?? true])
                ->all(),
            'delivery_mode' => $delayed['mode'] ?? 'immediate',
            'digest_time' => $delayed['digest_time'] ?? '08:00',
        ];
    }
}
