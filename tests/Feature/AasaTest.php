<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AasaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ios.apple_team_id' => 'TESTTEAMID',
            'ios.app_bundle_id' => 'co.cronx.spark',
        ]);
    }

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
            ->assertJsonPath('applinks.details.0.appID', 'TESTTEAMID.co.cronx.spark');
    }

    #[Test]
    public function aasa_response_contains_events_path(): void
    {
        $response = $this->getJson('/.well-known/apple-app-site-association');

        $paths = $response->json('applinks.details.0.paths');

        $this->assertContains('/events/*', $paths);
    }

    #[Test]
    public function aasa_response_contains_objects_path(): void
    {
        $response = $this->getJson('/.well-known/apple-app-site-association');

        $paths = $response->json('applinks.details.0.paths');

        $this->assertContains('/objects/*', $paths);
    }

    #[Test]
    public function aasa_response_contains_places_path(): void
    {
        $response = $this->getJson('/.well-known/apple-app-site-association');

        $paths = $response->json('applinks.details.0.paths');

        $this->assertContains('/places/*', $paths);
    }

    #[Test]
    public function aasa_response_contains_webcredentials(): void
    {
        $response = $this->getJson('/.well-known/apple-app-site-association');

        $response->assertOk()
            ->assertJsonPath('webcredentials.apps.0', 'TESTTEAMID.co.cronx.spark');
    }
}
