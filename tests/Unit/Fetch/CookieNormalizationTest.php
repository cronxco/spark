<?php

namespace Tests\Unit\Fetch;

use App\Integrations\Fetch\FetchHttpClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CookieNormalizationTest extends TestCase
{
    #[Test]
    public function it_reads_legacy_name_value_cookies(): void
    {
        $normalized = FetchHttpClient::normalizeCookies([
            'session' => 'abc',
            'token' => 'xyz',
        ]);

        $this->assertCount(2, $normalized);
        $this->assertSame('session', $normalized[0]['name']);
        $this->assertSame('abc', $normalized[0]['value']);
        $this->assertTrue($normalized[0]['secure']);
        $this->assertNull($normalized[0]['expires']);
    }

    #[Test]
    public function it_round_trips_rich_cookie_objects_preserving_attributes(): void
    {
        $normalized = FetchHttpClient::normalizeCookies([
            [
                'name' => 'sid',
                'value' => 'v1',
                'domain' => '.example.com',
                'path' => '/app',
                'secure' => false,
                'httpOnly' => false,
                'sameSite' => 'None',
                'expires' => 1893456000,
            ],
        ]);

        $this->assertCount(1, $normalized);
        $cookie = $normalized[0];
        $this->assertSame('sid', $cookie['name']);
        $this->assertSame('/app', $cookie['path']);
        $this->assertFalse($cookie['secure']);
        $this->assertFalse($cookie['httpOnly']);
        $this->assertSame('None', $cookie['sameSite']);
        $this->assertSame(1893456000, $cookie['expires']);
    }
}
