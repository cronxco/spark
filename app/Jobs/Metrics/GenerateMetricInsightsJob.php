<?php

namespace App\Jobs\Metrics;

use App\Models\MetricStatistic;
use App\Models\User;
use App\Services\Metrics\InsightEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Works out the day's metric insights. Scheduled hourly, so an insight shows
 * up once the data behind it is final; running it again changes nothing.
 */
class GenerateMetricInsightsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600;

    public $tries = 2;

    public $backoff = [120, 300];

    /**
     * @param  string|null  $userId  Limit the run to one user; null runs everyone.
     */
    public function __construct(public ?string $userId = null) {}

    public function uniqueId(): string
    {
        return $this->userId ?? 'all';
    }

    public function handle(InsightEngine $engine): void
    {
        $userIds = MetricStatistic::query()
            ->when($this->userId, fn ($q) => $q->where('user_id', $this->userId))
            ->whereNotNull('mean_value')
            ->distinct()
            ->pluck('user_id');

        foreach (User::whereIn('id', $userIds)->get() as $user) {
            try {
                $engine->generateForUser($user);
            } catch (Throwable $e) {
                // One person's data must not stop the rest.
                Log::warning('Metric insights failed for a user', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
