<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Notifications\NotificationCatalogue;
use App\Services\Api\ResourceVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NotificationSettingsController extends Controller
{
    public function __construct(private ResourceVersion $versions) {}

    /**
     * GET /api/v1/mobile/settings/notifications
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->mobilePreferences($request));
    }

    /**
     * PATCH /api/v1/mobile/settings/notifications
     */
    public function update(Request $request): JsonResponse
    {
        $categoryRules = collect($this->categories())
            ->mapWithKeys(fn (string $category) => ["categories.{$category}" => ['required_unless:delivery_mode,work_hours', 'boolean']])
            ->all();

        $validated = $request->validate([
            'categories' => ['sometimes', 'array'],
            'delivery_mode' => ['required', 'string', Rule::in(['immediate', 'work_hours', 'daily_digest'])],
            'digest_time' => ['nullable', 'date_format:H:i'],
            ...$categoryRules,
        ]);

        $categories = $validated['categories'] ?? [];
        $categories = array_replace(
            array_fill_keys($this->categories(), true),
            array_intersect_key($categories, array_flip($this->categories())),
        );

        $request->user()->updateNotificationPreferences([
            'push_types' => $categories,
            'delayed_sending' => [
                'mode' => $validated['delivery_mode'],
                'digest_time' => $validated['digest_time'] ?? '08:00',
            ],
        ]);

        if ($validated['delivery_mode'] === 'work_hours') {
            return response()->json(null, 204)->header('ETag', $this->versions->etag($request->user()->fresh()));
        }

        return response()->json($this->mobilePreferences($request))
            ->header('ETag', $this->versions->etag($request->user()->fresh()));
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

    private function mobilePreferences(Request $request): array
    {
        $notifications = $request->user()->fresh()->settings['notifications'] ?? [];
        $pushTypes = $notifications['push_types'] ?? [];
        $delayedSending = $notifications['delayed_sending'] ?? [];

        return [
            'categories' => collect($this->categories())
                ->mapWithKeys(fn (string $category) => [$category => $pushTypes[$category] ?? true])
                ->all(),
            'delivery_mode' => $delayedSending['mode'] ?? 'immediate',
            'digest_time' => $delayedSending['digest_time'] ?? '08:00',
        ];
    }
}
