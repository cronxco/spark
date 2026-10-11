<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Flint\FlintScheduleService;
use App\Services\Flint\FlintScheduleSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The schedules behind the Flint settings editor: when each repeating routine runs. Validation is
 * shared with the web editor, so a malformed time or cron is refused with a 422 and nothing is stored.
 */
class FlintSchedulesController extends Controller
{
    public function __construct(private FlintScheduleService $schedules) {}

    /**
     * GET /api/v1/mobile/flint/schedules
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => array_map(fn (string $routine): array => $this->present($request->user(), $routine), FlintScheduleSettings::EDITABLE_ROUTINES),
        ]);
    }

    /**
     * PUT /api/v1/mobile/flint/schedules/{routine}
     *
     * Body: `enabled` (bool, optional) plus the schedule fields `frequency`, `days`, `day_of_month`,
     * `times` and `cron`.
     */
    public function update(Request $request, string $routine): JsonResponse
    {
        abort_unless(in_array($routine, FlintScheduleSettings::EDITABLE_ROUTINES, true), 404);

        $request->validate(['enabled' => ['sometimes', 'boolean']]);
        $schedule = FlintScheduleSettings::validateSchedule($request->except('enabled'));

        $user = $request->user();
        $settings = $user->settings;
        if ($request->has('enabled')) {
            $settings['flint']["{$routine}_enabled"] = $request->boolean('enabled');
        }
        $settings['flint']['schedules'][$routine] = $schedule;
        $user->settings = $settings;
        $user->save();

        return response()->json(['data' => $this->present($user->fresh(), $routine)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user, string $routine): array
    {
        $enabled = $this->schedules->enabled($user, $routine);

        return [
            'routine' => $routine,
            'enabled' => $enabled,
            'schedule' => $this->schedules->cadence($user, $routine),
            'default_time' => $this->schedules->slot($user, $routine),
            'next_run' => $enabled ? $this->schedules->nextEligibleRun($user, $routine)->toIso8601String() : null,
        ];
    }
}
