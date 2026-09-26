<?php

namespace App\Integrations\Contracts;

interface SupportsSweeps
{
    /**
     * Describe the periodic back-fill sweep this plugin runs alongside its
     * incremental fetches. `config_key` is the integration configuration key
     * the plugin stamps with an ISO-8601 time after each sweep.
     *
     * @return array{label: string, window: string, period_hours: int, config_key: string}
     */
    public static function getSweepSchedule(): array;
}
