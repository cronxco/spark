<?php

namespace App\Services\Ai;

use App\Models\Event;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Arr;

class AiUsageSummary
{
    /**
     * @return array<int, array{model: string, request_count: int, input_tokens: int, output_tokens: int, total_tokens: int, cached_tokens: int, reasoning_tokens: int, failure_count: int}>
     */
    public function for(User $user, int $days, bool $flintOnly = false): array
    {
        $end = Carbon::now($user->getTimezone())->toDateString();
        $start = Carbon::parse($end, $user->getTimezone())->subDays(max(1, $days) - 1)->toDateString();
        $fields = ['request_count', 'input_tokens', 'output_tokens', 'total_tokens', 'cached_tokens', 'reasoning_tokens', 'failure_count'];

        $rows = Event::query()
            ->where('service', 'openai')
            ->where('action', 'used_ai')
            ->whereHas('integration', fn ($query) => $query
                ->where('user_id', $user->id)
                ->where('instance_type', 'internal'))
            ->whereBetween('event_metadata->local_date', [$start, $end])
            ->get()
            ->groupBy(fn (Event $event) => (string) Arr::get($event->event_metadata, 'model', 'unknown'))
            ->map(function ($events, string $model) use ($fields, $flintOnly): array {
                $summary = ['model' => $model];
                foreach ($fields as $field) {
                    $summary[$field] = (int) $events->sum(fn (Event $event) => (int) Arr::get(
                        $event->event_metadata,
                        $flintOnly ? "services.flint.{$field}" : $field,
                        0,
                    ));
                }

                return $summary;
            })
            ->filter(fn (array $row) => array_sum(Arr::except($row, ['model'])) > 0)
            ->sortByDesc('total_tokens')
            ->values()
            ->all();

        return $rows;
    }
}
