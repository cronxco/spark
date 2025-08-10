<?php

namespace App\Jobs\Migrations;

use App\Integrations\PluginRegistry;
use App\Models\Integration;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class StartIntegrationMigration implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 3;
    public array $backoff = [60, 300, 600];

    protected Integration $integration;
    protected ?Carbon $timeboxUntil;
    protected array $options;

    public function __construct(Integration $integration, ?Carbon $timeboxUntil = null, array $options = [])
    {
        $this->integration = $integration;
        $this->timeboxUntil = $timeboxUntil;
        $this->options = $options;
        $this->onConnection('redis');
        $this->onQueue('migration');
    }

    public function handle(): void
    {
        $service = $this->integration->service;
        if ($service === 'oura') {
            $this->startOura();
            return;
        }
        if ($service === 'spotify') {
            $this->startSpotify();
            return;
        }
        if ($service === 'github') {
            $this->startGitHub();
            return;
        }
        Log::info('StartIntegrationMigration: unsupported service, skipping', [
            'service' => $service,
            'integration_id' => $this->integration->id,
        ]);
    }

    protected function startOura(): void
    {
        $type = $this->integration->instance_type ?: 'activity';
        // Date-window paging going backwards. Default windows: 30 days (daily endpoints), 7 days (heartrate)
        $now = Carbon::now();
        if ($type === 'heartrate') {
            $end = $now->copy();
            $start = $end->copy()->subDays(6);
            $context = [
                'service' => 'oura',
                'instance_type' => $type,
                'cursor' => [
                    'start_datetime' => $start->toIso8601String(),
                    'end_datetime' => $end->toIso8601String(),
                ],
                'window_days' => 7,
                'timebox_until' => $this->timeboxUntil?->toIso8601String(),
            ];
        } else {
            $end = $now->copy();
            $start = $end->copy()->subDays(29);
            $context = [
                'service' => 'oura',
                'instance_type' => $type,
                'cursor' => [
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->toDateString(),
                ],
                'window_days' => 30,
                'timebox_until' => $this->timeboxUntil?->toIso8601String(),
            ];
        }
        Bus::chain([
            new FetchIntegrationPage($this->integration, $context),
        ])->onConnection('redis')->onQueue('migration')->dispatch();
    }

    protected function startSpotify(): void
    {
        $nowMs = (int) round(microtime(true) * 1000);
        $context = [
            'service' => 'spotify',
            'instance_type' => $this->integration->instance_type ?: 'listening',
            'cursor' => [
                'before_ms' => $nowMs,
            ],
            'timebox_until' => $this->timeboxUntil?->toIso8601String(),
        ];
        Bus::chain([
            new FetchIntegrationPage($this->integration, $context),
        ])->onConnection('redis')->onQueue('migration')->dispatch();
    }

    protected function startGitHub(): void
    {
        $context = [
            'service' => 'github',
            'instance_type' => $this->integration->instance_type ?: 'activity',
            'cursor' => [
                'repo_index' => 0,
                'page' => 1,
            ],
            'timebox_until' => $this->timeboxUntil?->toIso8601String(),
        ];
        Bus::chain([
            new FetchIntegrationPage($this->integration, $context),
        ])->onConnection('redis')->onQueue('migration')->dispatch();
    }
}




