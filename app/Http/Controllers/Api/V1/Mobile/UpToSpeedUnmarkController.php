<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Models\MetricTrend;
use App\Models\User;
use App\Services\Mobile\AnomalyAcknowledgement;
use App\Services\Mobile\UpToSpeedSubjectResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Activitylog\Models\Activity;

class UpToSpeedUnmarkController extends Controller
{
    public function __construct(
        private UpToSpeedSubjectResolver $resolver,
        private AnomalyAcknowledgement $acknowledgement,
    ) {}

    /**
     * POST /api/v1/mobile/up-to-speed/unmark
     *
     * The inverse of `up-to-speed/read`: return one or more items to the unread
     * queue. This is the recovery path for something dismissed by accident —
     * without it a swipe past a card is irreversible, because the feed has no
     * other way to surface an item whose `caught_up` row already exists.
     *
     * For anomalies this also clears `acknowledged_at` and any suppression,
     * since those — not the activity row — are what actually evict an anomaly
     * from the feed.
     *
     * Idempotent — unmarking something already unread is a no-op.
     *
     * Body: [ { "type": "flint_digest|anomaly|news_summary", "id": "<uuid>" }, ... ]
     */
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.type' => ['required', 'string', Rule::in(UpToSpeedSubjectResolver::READABLE_TYPES)],
            'items.*.id' => ['required', 'string', 'uuid'],
        ]);

        $user = $request->user();
        $unmarked = 0;

        foreach ($validated['items'] as $item) {
            $subject = $this->resolver->resolve($item['type'], $item['id'], $user);

            if ($subject === null) {
                continue;
            }

            $deleted = Activity::query()
                ->where('causer_type', User::class)
                ->where('causer_id', $user->id)
                ->where('subject_type', $subject::class)
                ->where('subject_id', $subject->id)
                ->where('event', 'caught_up')
                ->delete();

            $released = $subject instanceof MetricTrend
                ? $this->acknowledgement->unacknowledge($user, $subject->id)
                : false;

            if ($deleted > 0 || $released) {
                $unmarked++;
            }
        }

        return response()->json(['unmarked' => $unmarked]);
    }
}
