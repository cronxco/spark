<?php

namespace App\Services\Diagnostics;

use Illuminate\Support\Facades\File;

class ConfigurationAudit
{
    /**
     * Return only variable names and fixed diagnostic messages, never values.
     */
    public function run(): array
    {
        $names = [];
        foreach (File::allFiles(config_path()) as $file) {
            preg_match_all('/env\s*\(\s*[\x27\x22]([A-Z][A-Z0-9_]*)[\x27\x22]/', $file->getContents(), $matches);
            foreach ($matches[1] as $name) {
                $names[$name] = true;
            }
        }
        ksort($names);

        // Explicit inputs are detected by name only. Laravel config caching
        // means env() cannot be used to identify source at request time.
        $explicit = array_fill_keys(array_keys($_ENV), true);
        foreach (array_keys($_SERVER) as $name) {
            $explicit[$name] = true;
        }
        foreach (array_keys(getenv()) as $name) {
            $explicit[$name] = true;
        }
        if (File::exists(base_path('.env'))) {
            foreach (File::lines(base_path('.env')) as $line) {
                if (preg_match('/^\s*(?:export\s+)?([A-Z][A-Z0-9_]*)\s*=/', $line, $match)) {
                    $explicit[$match[1]] = true;
                }
            }
        }

        $variables = [];
        foreach (array_keys($names) as $name) {
            $variables[] = ['name' => $name, 'source' => isset($explicit[$name]) ? 'explicit' : 'default_or_unset'];
        }

        $findings = [];
        $add = static function (string $severity, string $key, string $message) use (&$findings): void {
            $findings[] = ['severity' => $severity, 'key' => $key, 'message' => $message];
        };

        if (config('app.debug') && ! app()->environment(['local', 'testing'])) {
            $add('error', 'APP_DEBUG', 'Debug mode is enabled outside local/testing.');
        }
        if (config('app.enable_task_pipeline') && config('app.allowed_task_commands') === null) {
            $add('warning', 'ALLOWED_TASK_COMMANDS', 'Task pipeline is enabled without an Artisan command allowlist.');
        }
        if (config('fetch.list_detection.enabled') && ! config('services.jev.enabled')) {
            $add('warning', 'JEV_ENABLED', 'Fetch list detection is enabled while Jev is disabled.');
        }
        if (config('services.jev.enabled') && blank(config('services.jev.api_key'))) {
            $add('error', 'TYPESAFE_API_KEY', 'Jev is enabled without a configured API key.');
        }
        if (config('services.playwright.enabled') && blank(config('services.playwright.worker_url'))) {
            $add('error', 'PLAYWRIGHT_WORKER_URL', 'Playwright is enabled without a worker URL.');
        }
        if (config('app.trusted_proxies') === '*') {
            $add('info', 'TRUSTED_PROXIES', 'Wildcard trusted proxies: verify the network boundary.');
        }
        if (! config('fetch.list_detection.enabled')) {
            $add('info', 'FETCH_LIST_DETECTION_ENABLED', 'Fetch list detection is disabled.');
        }

        return [
            'summary' => [
                'total' => count($variables),
                'explicit' => count(array_filter($variables, fn ($v) => $v['source'] === 'explicit')),
                'default_or_unset' => count(array_filter($variables, fn ($v) => $v['source'] === 'default_or_unset')),
                'errors' => count(array_filter($findings, fn ($v) => $v['severity'] === 'error')),
                'warnings' => count(array_filter($findings, fn ($v) => $v['severity'] === 'warning')),
            ],
            'variables' => $variables,
            'findings' => $findings,
        ];
    }
}
