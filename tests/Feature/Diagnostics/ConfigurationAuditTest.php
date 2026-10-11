<?php

namespace Tests\Feature\Diagnostics;

use App\Services\Diagnostics\ConfigurationAudit;
use Tests\TestCase;

class ConfigurationAuditTest extends TestCase
{
    public function test_audit_does_not_expose_secret_values(): void
    {
        config()->set('services.jev.enabled', true);
        config()->set('services.jev.api_key', 'extremely-secret-test-value');
        config()->set('app.debug', true);
        config()->set('app.env', 'staging');

        $report = app(ConfigurationAudit::class)->run();

        $this->assertArrayHasKey('summary', $report);
        $this->assertArrayHasKey('variables', $report);
        $this->assertArrayHasKey('findings', $report);
        $this->assertStringNotContainsString('extremely-secret-test-value', json_encode($report));
        $this->assertContains('TYPESAFE_API_KEY', array_column($report['variables'], 'name'));
        $this->assertNotContains('extremely-secret-test-value', array_column($report['variables'], 'name'));
    }

    public function test_disabled_jev_with_enabled_list_detection_generates_warning(): void
    {
        config()->set('fetch.list_detection.enabled', true);
        config()->set('services.jev.enabled', false);

        $report = app(ConfigurationAudit::class)->run();

        $this->assertContains('JEV_ENABLED', array_column($report['findings'], 'key'));
    }

    public function test_command_json_is_redacted(): void
    {
        config()->set('services.jev.api_key', 'do-not-print-me');

        $this->artisan('config:audit', ['--json' => true])
            ->doesntExpectOutputToContain('do-not-print-me');
    }
}
