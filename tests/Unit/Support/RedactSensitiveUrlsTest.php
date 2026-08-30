<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RedactSensitiveUrlsTest extends TestCase
{
    private const URL = 'https://mcp.example.test/tok_abc123secret/sse';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.flint_routine.cronxtools_url' => self::URL]);
    }

    #[Test]
    public function it_redacts_the_mcp_url_wherever_it_appears_in_a_string(): void
    {
        $body = 'Request failed for ' . self::URL . ' after 3 attempts';

        $redacted = redact_sensitive_urls($body);

        $this->assertStringNotContainsString('tok_abc123secret', $redacted);
        $this->assertStringContainsString('[REDACTED_MCP_URL]', $redacted);
    }

    #[Test]
    public function it_leaves_unrelated_text_alone(): void
    {
        $this->assertSame('nothing secret here', redact_sensitive_urls('nothing secret here'));
    }

    #[Test]
    public function it_is_a_no_op_when_no_mcp_url_is_configured(): void
    {
        config(['services.flint_routine.cronxtools_url' => null]);

        $this->assertSame('some text', redact_sensitive_urls('some text'));
    }

    #[Test]
    public function log_sanitisation_strips_the_token_from_nested_values(): void
    {
        $sanitised = sanitizeData([
            'tools' => [['server_url' => self::URL]],
            'note' => 'connecting to ' . self::URL,
            'safe' => 'keep me',
        ]);

        $encoded = json_encode($sanitised);

        $this->assertStringNotContainsString('tok_abc123secret', $encoded);
        $this->assertSame('keep me', $sanitised['safe']);
    }
}
