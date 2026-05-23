<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\MetricTrend;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Activitylog\Models\Activity;

class UpToSpeedReadController extends Controller
{
    /**
     * POST /api/v1/mobile/up-to-speed/read
     *
     * Mark one or more Up to Speed items as caught up in the activity log.
     * Idempotent — reposting the same items is a no-op.
     *
     * Body: [ { "type": "flint_digest|anomaly|news_summary", "id": "<uuid>" }, ... ]
     */
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.type' => ['required', 'string', Rule::in(['flint_digest', 'anomaly', 'news_summary'])],
            'items.*.id' => ['required', 'string', 'uuid'],
        ]);

        $user = $request->user();
        $integrationIds = $user->integrations()->pluck('id');
        $marked = 0;

        foreach ($validated['items'] as $item) {
            $subject = $this->resolveSubject($item['type'], $item['id'], $user, $integrationIds);

            if ($subject === null) {
                continue;
            }

            $alreadyMarked = Activity::query()
                ->where('causer_type', User::class)
                ->where('causer_id', $user->id)
                ->where('subject_type', get_class($subject))
                ->where('subject_id', $subject->id)
                ->where('event', 'caught_up')
                ->exists();

            if ($alreadyMarked) {
                continue;
            }

            activity('changelog')
                ->performedOn($subject)
                ->causedBy($user)
                ->event('caught_up')
                ->log('caught_up');

            $marked++;
        }

        return response()->json(['marked' => $marked]);
    }

    /**
     * Resolve a typed {type, id} pair to its Eloquent model, verifying ownership.
     * Returns null if not found or not owned by the user.
     */
    private function resolveSubject(string $type, string $id, User $user, mixed $integrationIds): Event|MetricTrend|null
    {
        return match ($type) {
            'flint_digest' => Event::whereIn('integration_id', $integrationIds)
                ->where('service', 'flint')
                ->where('action', 'had_summary')
                ->find($id),

            'anomaly' => MetricTrend::query()
                ->whereHas('metricStatistic', fn ($q) => $q->where('user_id', $user->id))
                ->find($id),

            'news_summary' => Event::whereIn('integration_id', $integrationIds)
                ->where('domain', 'knowledge')
                ->find($id),

            default => null,
        };
    }
}
