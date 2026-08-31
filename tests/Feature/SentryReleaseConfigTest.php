<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SentryReleaseConfigTest extends TestCase
{
    private string $versionPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->versionPath = base_path('VERSION');
        $this->removeVersionFile();
    }

    protected function tearDown(): void
    {
        $this->removeVersionFile();
        putenv('SENTRY_RELEASE');
        parent::tearDown();
    }

    #[Test]
    public function release_is_the_trimmed_contents_of_the_version_file_when_present(): void
    {
        File::put($this->versionPath, "sparkapp@9.9.9+abcdef\n");

        $this->refreshApplication();

        $this->assertSame('sparkapp@9.9.9+abcdef', config('sentry.release'));
    }

    #[Test]
    public function release_falls_back_to_the_env_var_when_no_version_file_exists(): void
    {
        putenv('SENTRY_RELEASE=sparkapp@1.2.3+local');

        $this->refreshApplication();

        $this->assertSame('sparkapp@1.2.3+local', config('sentry.release'));
    }

    #[Test]
    public function release_is_null_when_neither_version_file_nor_env_var_is_set(): void
    {
        putenv('SENTRY_RELEASE');

        $this->refreshApplication();

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

    private function removeVersionFile(): void
    {
        if (File::exists($this->versionPath)) {
            File::delete($this->versionPath);
        }
    }
}
