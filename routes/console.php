<?php

use App\Jobs\CheckIntegrationUpdates;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule integration update check job every minute
Schedule::job(new CheckIntegrationUpdates())
    ->everyMinute()
    ->withoutOverlapping();
