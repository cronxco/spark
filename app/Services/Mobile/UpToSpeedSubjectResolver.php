<?php

namespace App\Services\Mobile;

use App\Models\Event;
use App\Models\MetricTrend;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Resolves a typed Up to Speed `{type, id}` pair to its Eloquent model,
 * verifying ownership. Shared by the read and unmark endpoints so both apply
 * exactly the same tenancy check — a mismatch between them would let an item
 * be marked caught up but never released, or vice versa.
 */
class UpToSpeedSubjectResolver
{
    /**
     * The item types that carry a `caught_up` read state. `check_in` is absent
     * deliberately: its read signal is the existence of the check-in event, and
     * its synthetic `{period}:{date}` id is not a UUID.
     *
     * @var array<int, string>
     */
    public const READABLE_TYPES = ['flint_digest', 'anomaly', 'news_summary'];

    public function resolve(string $type, string $id, User $user): Event|MetricTrend|null
    {
        $integrationIds = $this->integrationIds($user);

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

    /**
     * @return Collection<int, mixed>
     */
    private function integrationIds(User $user): Collection
    {
        return $user->integrations()->pluck('id');
    }
}
