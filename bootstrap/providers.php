<?php

use App\Providers\AppServiceProvider;
use App\Providers\CardServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\IconServiceProvider;
use App\Providers\IntegrationServiceProvider;
use App\Providers\SpotlightServiceProvider;
use App\Providers\TaskPipelineServiceProvider;
use App\Providers\VoltServiceProvider;
use Livewire\LivewireServiceProvider;
use SocialiteProviders\Manager\ServiceProvider;

return [
    AppServiceProvider::class,
    CardServiceProvider::class,
    HorizonServiceProvider::class,
    IconServiceProvider::class,
    IntegrationServiceProvider::class,
    SpotlightServiceProvider::class,
    TaskPipelineServiceProvider::class,
    VoltServiceProvider::class,
    LivewireServiceProvider::class,
    ServiceProvider::class,
    WireElements\Pro\Components\Spotlight\SpotlightServiceProvider::class,
];
