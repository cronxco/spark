<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NotificationPreferencesController extends Controller
{
    private const CATEGORIES = [
        'anomaly',
        'digest',
        'integration_failed',
        'new_bookmark',
        'calendar_event',
    ];

    /**
     * GET /api/v1/mobile/settings/notifications
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->mobilePayload($request->user()->getNotificationPreferences()));
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
        $categories = array_intersect_key($categories, array_flip(self::CATEGORIES));

        $request->user()->updateNotificationPreferences([
            'push_types' => $categories,
            'delayed_sending' => [
                'mode' => $validated['delivery_mode'],
                'digest_time' => $validated['digest_time'] ?? '09:00',
            ],
        ]);

        return response()->json(null, 204);
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
            'categories' => collect(self::CATEGORIES)
                ->mapWithKeys(fn (string $category) => [$category => $pushTypes[$category] ?? true])
                ->all(),
            'delivery_mode' => $delayed['mode'] ?? 'immediate',
            'digest_time' => $delayed['digest_time'] ?? '09:00',
        ];
    }
}
