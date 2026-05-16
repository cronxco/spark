<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NotificationSettingsController extends Controller
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
        return response()->json($this->mobilePreferences($request));
    }

    /**
     * PATCH /api/v1/mobile/settings/notifications
     */
    public function update(Request $request): JsonResponse
    {
        $categoryRules = collect(self::CATEGORIES)
            ->mapWithKeys(fn (string $category) => ["categories.{$category}" => ['required', 'boolean']])
            ->all();

        $validated = $request->validate([
            'categories' => ['required', 'array'],
            'delivery_mode' => ['required', 'string', Rule::in(['immediate', 'daily_digest'])],
            'digest_time' => ['required', 'date_format:H:i'],
            ...$categoryRules,
        ]);

        $request->user()->updateNotificationPreferences([
            'push_types' => $validated['categories'],
            'delayed_sending' => [
                'mode' => $validated['delivery_mode'],
                'digest_time' => $validated['digest_time'],
            ],
        ]);

        return response()->json($this->mobilePreferences($request));
    }

    private function mobilePreferences(Request $request): array
    {
        $notifications = $request->user()->fresh()->settings['notifications'] ?? [];
        $pushTypes = $notifications['push_types'] ?? [];
        $delayedSending = $notifications['delayed_sending'] ?? [];

        return [
            'categories' => collect(self::CATEGORIES)
                ->mapWithKeys(fn (string $category) => [$category => $pushTypes[$category] ?? true])
                ->all(),
            'delivery_mode' => $delayedSending['mode'] ?? 'immediate',
            'digest_time' => $delayedSending['digest_time'] ?? '08:00',
        ];
    }
}
