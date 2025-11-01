<?php

use App\Jobs\CheckIntegrationUpdates;
use App\Jobs\Metrics\CalculateMetricStatisticsJob;
use App\Jobs\Metrics\DetectMetricTrendsJob;
use App\Jobs\Metrics\DetectRetrospectiveMetricAnomaliesJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule integration update check job every minute
Schedule::job(new CheckIntegrationUpdates)
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// Calculate metric statistics hourly
Schedule::job(new CalculateMetricStatisticsJob)
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

// Detect metric trends daily
Schedule::job(new DetectMetricTrendsJob)
    ->daily()
    ->withoutOverlapping()
    ->onOneServer();

// Detect retrospective metric anomalies daily
Schedule::job(new DetectRetrospectiveMetricAnomaliesJob)
    ->daily()
    ->withoutOverlapping()
    ->onOneServer();
