<?php

namespace Tests\Unit\Support;

use App\Integrations\Oura\OuraPlugin;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IntegrationResponseRedactionTest extends TestCase
{
    #[Test]
    public function response_credentials_are_redacted_before_large_bodies_are_truncated(): void
    {
        $body = json_encode([
            'access' => 'access-canary',
            'refresh' => 'refresh-canary',
            'data' => [['iban' => 'iban-canary', 'maskedPan' => 'pan-canary']],
            'customToken' => 'token-canary',
            'padding' => str_repeat('x', 11000),
        ]);

        $logged = sanitize_api_response_body('/accounts', $body);

        foreach (['access-canary', 'refresh-canary', 'iban-canary', 'pan-canary', 'token-canary'] as $secret) {
            $this->assertStringNotContainsString($secret, $logged);
        }
        $this->assertStringContainsString('[REDACTED]', $logged);
        $this->assertStringContainsString('[TRUNCATED]', $logged);
    }

    #[Test]
    public function token_endpoint_bodies_are_omitted_even_when_malformed(): void
    {
        $this->assertSame('[REDACTED TOKEN RESPONSE]', sanitize_api_response_body('https://example.com/oauth/token?grant=refresh', 'private-canary'));
        $this->assertSame('[REDACTED TOKEN RESPONSE]', sanitize_api_response_body('/token/new/', '{"unexpected":"private-canary"}'));
        $this->assertSame('[NON-JSON RESPONSE OMITTED]', sanitize_api_response_body('/accounts', 'access_token=private-canary'));
    }

    #[Test]
    public function normal_json_keeps_useful_response_fields(): void
    {
        $logged = sanitize_api_response_body('/accounts', '{"status":"ok","balance":42}');
        $this->assertSame(['status' => 'ok', 'balance' => 42], json_decode($logged, true));
    }

    #[Test]
    public function webhook_and_session_headers_are_redacted_case_insensitively(): void
    {
        $headers = sanitizeHeaders([
            'Cookie' => ['session=private-canary'],
            'Set-Cookie' => 'session=private-canary',
            'X-Webhook-Secret' => 'private-canary',
            'Session-Id' => 'private-canary',
            'Content-Type' => 'application/json',
        ]);

        $this->assertStringNotContainsString('private-canary', json_encode($headers));
        $this->assertSame(['application/json'], $headers['Content-Type']);
    }

    #[Test]
    public function fallback_response_logger_receives_only_sanitized_body(): void
    {
        Log::spy();

        log_integration_api_response('test', 'GET', '/accounts', 200, '{"access":"private-canary"}', [], 'invalid-uuid');

        Log::shouldHaveReceived('debug')->withArgs(function (string $message, array $context): bool {
            return $message === 'API Response'
                && ! str_contains($context['response_body'], 'private-canary')
                && str_contains($context['response_body'], '[REDACTED]');
        })->once();
    }

    #[Test]
    public function pagination_cursors_and_token_counts_stay_visible(): void
    {
        $logged = sanitizeData([
            'next_token' => 'cursor-1',
            'nextPageToken' => 'cursor-2',
            'usage' => ['total_tokens' => 42],
            'access_token' => 'private-canary',
            'refresh_token' => 'private-canary',
        ]);

        $this->assertSame('cursor-1', $logged['next_token']);
        $this->assertSame('cursor-2', $logged['nextPageToken']);
        $this->assertSame(42, $logged['usage']['total_tokens']);
        $this->assertSame('[REDACTED]', $logged['access_token']);
        $this->assertSame('[REDACTED]', $logged['refresh_token']);
    }

    #[Test]
    public function plugin_loggers_pass_large_json_bodies_to_the_central_sanitizer(): void
    {
        $plugin = new OuraPlugin;
        $this->assertFalse(method_exists($plugin, 'sanitizeResponseBody'));

        $body = json_encode(['data' => array_fill(0, 600, ['bpm' => 60, 'source' => 'awake']), 'next_token' => 'abc']);
        $logged = sanitize_api_response_body('/usercollection/heartrate', $body);

        $this->assertStringStartsWith('{"data":', $logged);
        $this->assertStringEndsWith('... [TRUNCATED]', $logged);
    }
}
