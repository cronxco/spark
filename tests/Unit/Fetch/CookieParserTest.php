<?php

namespace Tests\Unit\Fetch;

use App\Integrations\Fetch\CookieParser;
use PHPUnit\Framework\TestCase;

class CookieParserTest extends TestCase
{
    /** @test */
    public function it_parses_standard_format_with_expiry()
    {
        $json = json_encode([
            ['name' => 'session_id', 'value' => 'abc123', 'expires' => 1735689600],
            ['name' => 'auth_token', 'value' => 'xyz789', 'expires' => 1735689600],
        ]);

        $result = CookieParser::parse($json);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['cookies']);
        $this->assertEquals('abc123', $result['cookies']['session_id']);
        $this->assertEquals('xyz789', $result['cookies']['auth_token']);
        $this->assertEquals('2025-01-01T00:00:00+00:00', $result['expires_at']);
    }

    /** @test */
    public function it_parses_simple_key_value_format()
    {
        $json = json_encode([
            'session_id' => 'abc123',
            'auth_token' => 'xyz789',
        ]);

        $result = CookieParser::parse($json);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['cookies']);
        $this->assertEquals('abc123', $result['cookies']['session_id']);
        $this->assertEquals('xyz789', $result['cookies']['auth_token']);
        $this->assertNull($result['expires_at']);
    }

    /** @test */
    public function it_parses_har_format()
    {
        $json = json_encode([
            ['name' => 'session_id', 'value' => 'abc123', 'expirationDate' => 1735689600],
            ['name' => 'auth_token', 'value' => 'xyz789', 'expirationDate' => 1735689600],
        ]);

        $result = CookieParser::parse($json);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['cookies']);
        $this->assertEquals('abc123', $result['cookies']['session_id']);
        $this->assertEquals('2025-01-01T00:00:00+00:00', $result['expires_at']);
    }

    /** @test */
    public function it_handles_invalid_json()
    {
        $result = CookieParser::parse('not valid json');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid JSON', $result['error']);
    }

    /** @test */
    public function it_handles_empty_json()
    {
        $result = CookieParser::parse('{}');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No cookies found', $result['error']);
    }

    /** @test */
    public function it_handles_unsupported_format()
    {
        $json = json_encode(['invalid' => 'format']);

        $result = CookieParser::parse($json);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unsupported cookie format', $result['error']);
    }

    /** @test */
    public function it_formats_for_storage_correctly()
    {
        $parsed = [
            'success' => true,
            'cookies' => [
                'session_id' => 'abc123',
                'auth_token' => 'xyz789',
            ],
            'expires_at' => '2025-01-01T00:00:00+00:00',
        ];

        $domain = 'example.com';
        $formatted = CookieParser::formatForStorage($parsed, $domain);

        $this->assertArrayHasKey('cookies', $formatted);
        $this->assertArrayHasKey('headers', $formatted);
        $this->assertArrayHasKey('added_at', $formatted);
        $this->assertArrayHasKey('expires_at', $formatted);
        $this->assertEquals('2025-01-01T00:00:00+00:00', $formatted['expires_at']);
        $this->assertArrayHasKey('User-Agent', $formatted['headers']);
    }

    /** @test */
    public function it_calculates_expiry_status_correctly()
    {
        $now = now();

        // Green status (> 7 days)
        $expiresAt = $now->copy()->addDays(10)->toIso8601String();
        $this->assertEquals('green', CookieParser::getExpiryStatus($expiresAt));

        // Yellow status (3-7 days)
        $expiresAt = $now->copy()->addDays(5)->toIso8601String();
        $this->assertEquals('yellow', CookieParser::getExpiryStatus($expiresAt));

        // Red status (< 3 days)
        $expiresAt = $now->copy()->addDays(2)->toIso8601String();
        $this->assertEquals('red', CookieParser::getExpiryStatus($expiresAt));

        // Red status (expired)
        $expiresAt = $now->copy()->subDays(1)->toIso8601String();
        $this->assertEquals('red', CookieParser::getExpiryStatus($expiresAt));

        // Gray status (no expiry)
        $this->assertEquals('gray', CookieParser::getExpiryStatus(null));
    }

    /** @test */
    public function it_extracts_earliest_expiry_from_multiple_cookies()
    {
        $json = json_encode([
            ['name' => 'cookie1', 'value' => 'abc', 'expires' => 1735689600], // Later
            ['name' => 'cookie2', 'value' => 'def', 'expires' => 1704067200], // Earlier
            ['name' => 'cookie3', 'value' => 'ghi', 'expires' => 1767225600], // Latest
        ]);

        $result = CookieParser::parse($json);

        $this->assertTrue($result['success']);
        // Should use the earliest expiry date
        $this->assertEquals('2024-01-01T00:00:00+00:00', $result['expires_at']);
    }

    /** @test */
    public function it_handles_mixed_expiry_formats()
    {
        $json = json_encode([
            ['name' => 'cookie1', 'value' => 'abc', 'expires' => 1735689600],
            ['name' => 'cookie2', 'value' => 'def', 'expirationDate' => 1735689600],
            ['name' => 'cookie3', 'value' => 'ghi'], // No expiry
        ]);

        $result = CookieParser::parse($json);

        $this->assertTrue($result['success']);
        $this->assertCount(3, $result['cookies']);
        $this->assertNotNull($result['expires_at']);
    }
}
