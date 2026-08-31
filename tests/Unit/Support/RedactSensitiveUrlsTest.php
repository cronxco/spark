<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\Test;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\Options;
use Sentry\Serializer\PayloadSerializer;
use Tests\TestCase;

class RedactSensitiveUrlsTest extends TestCase
{
    private const URL = 'https://mcp.example.test:8443/tok_abc123secret/sse';

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

    #[Test]
    public function it_redacts_json_escaped_and_url_encoded_credential_urls(): void
    {
        $escaped = str_replace('/', '\\/', self::URL);
        $encoded = rawurlencode(self::URL);

        $redacted = redact_sensitive_urls("{$escaped} {$encoded}");

        $this->assertStringNotContainsString('tok_abc123secret', $redacted);
        $this->assertSame(2, substr_count($redacted, '[REDACTED_MCP_URL]'));
    }

    #[Test]
    public function complete_sentry_events_are_scrubbed_recursively(): void
    {
        $event = Event::createEvent();
        $event->setRequest(['url' => self::URL, 'data' => ['escaped' => str_replace('/', '\\/', self::URL)]]);
        $event->setExtra(['encoded' => rawurlencode(self::URL)]);
        $event->setBreadcrumb([new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_HTTP, 'mcp', self::URL, ['url' => self::URL])]);

        $encoded = (new PayloadSerializer(new Options(['dsn' => 'https://public@example.test/1'])))
            ->serialize(redact_sentry_event($event));

        $this->assertStringNotContainsString('tok_abc123secret', $encoded);
        $this->assertStringContainsString('REDACTED_MCP_URL', $encoded);
    }
}
