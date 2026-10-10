<?php

namespace Tests\Unit\Support;

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
        \Illuminate\Support\Facades\Log::spy();

        log_integration_api_response('test', 'GET', '/accounts', 200, '{"access":"private-canary"}', [], 'invalid-uuid');

        \Illuminate\Support\Facades\Log::shouldHaveReceived('debug')->withArgs(function (string $message, array $context): bool {
            return $message === 'API Response'
                && ! str_contains($context['response_body'], 'private-canary')
                && str_contains($context['response_body'], '[REDACTED]');
        })->once();
    }
}
