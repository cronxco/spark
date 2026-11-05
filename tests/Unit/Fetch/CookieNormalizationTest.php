<?php

namespace Tests\Unit\Fetch;

use App\Integrations\Fetch\FetchHttpClient;
use App\Integrations\Fetch\PlaywrightFetchClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

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

    #[Test]
    public function it_converts_rich_cookie_objects_to_valid_playwright_cookies(): void
    {
        $client = (new ReflectionClass(PlaywrightFetchClient::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($client, 'convertCookiesToPlaywrightFormat');

        $cookies = $method->invoke($client, 'example.com', [
            [
                'name' => 'sid',
                'value' => 'v1',
                'domain' => '.example.com',
                'path' => '/app',
                'secure' => false,
                'httpOnly' => false,
                'sameSite' => 'None',
            ],
        ]);

        $this->assertCount(1, $cookies);
        $this->assertSame('sid', $cookies[0]['name']);
        $this->assertIsString($cookies[0]['name']);
        $this->assertSame('v1', $cookies[0]['value']);
        $this->assertSame('.example.com', $cookies[0]['domain']);
        $this->assertSame('/app', $cookies[0]['path']);
        $this->assertFalse($cookies[0]['secure']);
        $this->assertFalse($cookies[0]['httpOnly']);
        $this->assertSame('None', $cookies[0]['sameSite']);
    }
}
