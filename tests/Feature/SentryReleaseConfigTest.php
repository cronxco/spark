<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\FrameworkTestCase;

class SentryReleaseConfigTest extends FrameworkTestCase
{
    private string $versionPath;

    private string $fixtureRoot;

    private string|false $originalRelease;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureRoot = sys_get_temp_dir() . '/spark-version-' . bin2hex(random_bytes(12));
        File::ensureDirectoryExists($this->fixtureRoot);
        $this->versionPath = $this->fixtureRoot . '/VERSION';
        $this->originalRelease = getenv('SENTRY_RELEASE');
        $this->removeVersionFile();
    }

    protected function tearDown(): void
    {
        $this->removeVersionFile();
        File::deleteDirectory($this->fixtureRoot);
        putenv($this->originalRelease === false ? 'SENTRY_RELEASE' : 'SENTRY_RELEASE=' . $this->originalRelease);
        parent::tearDown();
    }

    #[Test]
    public function release_is_the_trimmed_contents_of_the_version_file_when_present(): void
    {
        File::put($this->versionPath, "sparkapp@9.9.9+abcdef\n");

        $this->reloadSentryConfig();

        $this->assertSame('sparkapp@9.9.9+abcdef', config('sentry.release'));
    }

    #[Test]
    public function release_is_trimmed_of_all_surrounding_whitespace(): void
    {
        File::put($this->versionPath, "  sparkapp@3.1.4+abc123 \n\n");

        $this->reloadSentryConfig();

        $this->assertSame('sparkapp@3.1.4+abc123', config('sentry.release'));
    }

    #[Test]
    public function the_version_file_takes_precedence_over_the_env_var(): void
    {
        File::put($this->versionPath, "sparkapp@2.0.0+deadbee\n");
        putenv('SENTRY_RELEASE=should-not-be-used');

        $this->reloadSentryConfig();

        $this->assertSame('sparkapp@2.0.0+deadbee', config('sentry.release'));
    }

    #[Test]
    public function release_is_null_when_no_version_file_and_no_release_env_var(): void
    {
        putenv('SENTRY_RELEASE');

        $this->reloadSentryConfig();

        $this->assertNull(config('sentry.release'));
    }

    #[Test]
    public function browser_dsn_is_exposed_under_the_js_config_key(): void
    {
        $this->assertTrue(config()->has('sentry.js.dsn'));
    }

    #[Test]
    public function sentry_partials_resolve_the_release_from_config_not_env(): void
    {
        foreach ([
            resource_path('views/partials/head.blade.php'),
            resource_path('views/components/layouts/app.blade.php'),
        ] as $partial) {
            $contents = File::get($partial);

            $this->assertStringContainsString("config('sentry.release')", $contents);
            $this->assertStringNotContainsString("env('SENTRY_RELEASE')", $contents);
            $this->assertStringNotContainsString("env('VITE_SENTRY_DSN')", $contents);
        }
    }

    private function reloadSentryConfig(): void
    {
        $basePath = $this->app->basePath();
        $configPath = config_path('sentry.php');

        try {
            $this->app->setBasePath($this->fixtureRoot);
            config(['sentry' => require $configPath]);
        } finally {
            $this->app->setBasePath($basePath);
        }
    }

    private function removeVersionFile(): void
    {
        if (File::exists($this->versionPath)) {
            File::delete($this->versionPath);
        }
    }
}
