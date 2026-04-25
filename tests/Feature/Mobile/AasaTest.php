<?php

namespace Tests\Feature\Mobile;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AasaTest extends TestCase
{
    #[Test]
    public function aasa_returns_expected_json_payload(): void
    {
        config([
            'ios.apple_team_id' => 'ABCDE12345',
            'ios.app_bundle_id' => 'co.cronx.spark',
        ]);

        $this->get('/.well-known/apple-app-site-association')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('applinks.details.0.appID', 'ABCDE12345.co.cronx.spark')
            ->assertJsonPath('applinks.details.0.paths.0', '/events/*')
            ->assertJsonPath('applinks.details.0.paths.1', '/objects/*')
            ->assertJsonPath('applinks.details.0.paths.2', '/places/*')
            ->assertJsonPath('webcredentials.apps.0', 'ABCDE12345.co.cronx.spark');
    }

    #[Test]
    public function aasa_returns_500_when_team_id_missing(): void
    {
        config([
            'ios.apple_team_id' => '',
            'ios.app_bundle_id' => 'co.cronx.spark',
        ]);

        $this->get('/.well-known/apple-app-site-association')
            ->assertStatus(500);
    }
}
