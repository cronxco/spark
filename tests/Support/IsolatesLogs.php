<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;
use Mockery\MockInterface;

trait IsolatesLogs
{
    private string $originalStoragePath;

    private string $isolatedStoragePath;

    protected function setUpIsolatesLogs(): void
    {
        $this->originalStoragePath = $this->app->storagePath();
        $this->isolatedStoragePath = sys_get_temp_dir() . '/spark-logs-' . bin2hex(random_bytes(12));
        File::ensureDirectoryExists($this->isolatedStoragePath . '/logs');
        $this->app->useStoragePath($this->isolatedStoragePath);

        foreach (config('logging.channels') as $name => $channel) {
            if (isset($channel['path']) && str_starts_with($channel['path'], $this->originalStoragePath . '/')) {
                config(["logging.channels.{$name}.path" => $this->isolatedStoragePath . substr($channel['path'], strlen($this->originalStoragePath))]);
                $this->app['log']->forgetChannel($name);
            }
        }
    }

    protected function tearDownIsolatesLogs(): void
    {
        $manager = $this->app['log'];
        if (! ($manager instanceof MockInterface)) {
            foreach (array_keys(config('logging.channels')) as $name) {
                $manager->forgetChannel($name);
            }
        }

        $this->app->useStoragePath($this->originalStoragePath);
        File::deleteDirectory($this->isolatedStoragePath);
    }
}
