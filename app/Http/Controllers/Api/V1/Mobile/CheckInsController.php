<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\Compact\CompactEventResource;
use App\Integrations\DailyCheckin\DailyCheckinPlugin;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CheckInsController extends Controller
{
    /**
     * POST /api/v1/mobile/check-ins
     *
     * Forwards to DailyCheckinPlugin::createCheckinEvent which owns all the
     * validation, event/object wiring, and location linking.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['required', 'string', 'in:morning,afternoon'],
            'physical' => ['required', 'integer', 'min:1', 'max:5'],
            'mental' => ['required', 'integer', 'min:1', 'max:5'],
            'date' => ['required', 'date_format:Y-m-d'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        $integration = $this->resolveIntegration($request);

        $event = (new DailyCheckinPlugin)->createCheckinEvent(
            $integration,
            $validated['period'],
            $validated['physical'],
            $validated['mental'],
            $validated['date'],
            $validated['latitude'] ?? null,
            $validated['longitude'] ?? null,
            $validated['address'] ?? null,
        );

        return response()->json(
            (new CompactEventResource($event))->resolve($request),
            201,
        );
    }

    protected function resolveIntegration(Request $request): Integration
    {
        $user = $request->user();

        $group = IntegrationGroup::firstOrCreate(
            ['user_id' => $user->id, 'service' => 'daily_checkin'],
            [
                'account_id' => Str::uuid()->toString(),
                'access_token' => 'mobile',
            ],
        );

        return Integration::firstOrCreate(
            ['user_id' => $user->id, 'service' => 'daily_checkin'],
            [
                'integration_group_id' => $group->id,
                'name' => 'Daily Check-in',
                'account_id' => $group->account_id,
            ],
        );
    }
}
