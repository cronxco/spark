<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AasaTest extends TestCase
{
    #[Test]
    public function aasa_endpoint_is_accessible_without_authentication(): void
    {
        $this->getJson('/.well-known/apple-app-site-association')
            ->assertOk();
    }

    #[Test]
    public function aasa_response_contains_correct_bundle_id(): void
    {
        $response = $this->getJson('/.well-known/apple-app-site-association');

        $response->assertOk()
            ->assertJsonPath('applinks.details.0.appIDs.0', 'co.cronx.spark');
    }

    #[Test]
    public function aasa_response_contains_today_path(): void
    {
        $response = $this->getJson('/.well-known/apple-app-site-association');

        $components = $response->json('applinks.details.0.components');

        $paths = array_column($components, '/');

        $this->assertContains('/today', $paths);
    }

    #[Test]
    public function aasa_response_contains_day_wildcard_path(): void
    {
        $response = $this->getJson('/.well-known/apple-app-site-association');

        $components = $response->json('applinks.details.0.components');

        $paths = array_column($components, '/');

        $this->assertContains('/day/*', $paths);
    }

    #[Test]
    public function aasa_response_contains_event_wildcard_path(): void
    {
        $response = $this->getJson('/.well-known/apple-app-site-association');

        $components = $response->json('applinks.details.0.components');

        $paths = array_column($components, '/');

        $this->assertContains('/event/*', $paths);
    }
}
