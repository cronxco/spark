<?php

namespace Tests\Unit\Mcp\Tracing;

use App\Mcp\Tracing\McpSpan;
use Illuminate\Http\Request;
use Laravel\Mcp\Server\Transport\HttpTransport;
use PHPUnit\Framework\Attributes\Test;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Tests\TestCase;

class McpSpanTest extends TestCase
{
    private HttpTransport $transport;

    private Transaction $transaction;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mcp.sentry.enabled' => true]);

        $this->transport = new HttpTransport(request: new Request);

        $context = new TransactionContext('mcp-test');
        $context->setSampled(true);
        $this->transaction = new Transaction($context, SentrySdk::getCurrentHub());
        $this->transaction->initSpanRecorder();
        SentrySdk::getCurrentHub()->setSpan($this->transaction);
    }

    protected function tearDown(): void
    {
        SentrySdk::getCurrentHub()->setSpan(null);

        parent::tearDown();
    }

    #[Test]
    public function a_tool_call_span_records_the_request_without_a_session_id(): void
    {
        $result = McpSpan::toolCall('get-event', $this->transport, 7, ['id' => 'abc'], fn (): string => 'done');

        $this->assertSame('done', $result);

        $span = $this->childSpans()[0];
        $this->assertSame('mcp.server', $span->getOp());
        $this->assertSame('tools/call get-event', $span->getDescription());
        $this->assertSame('tools/call', $span->getData()['mcp.method.name']);
        $this->assertSame('http', $span->getData()['mcp.transport']);
        $this->assertSame(7, $span->getData()['mcp.request.id']);
        $this->assertSame('abc', $span->getData()['mcp.request.argument.id']);
        $this->assertArrayNotHasKey('mcp.session.id', $span->getData());
    }

    #[Test]
    public function a_resource_read_span_records_the_uri(): void
    {
        McpSpan::resourceRead('spark://context/day/2026-10-13', $this->transport, 'req-1', fn (): bool => true);

        $span = $this->childSpans()[0];
        $this->assertSame('resources/read spark://context/day/2026-10-13', $span->getDescription());
        $this->assertSame('context', $span->getData()['mcp.resource.name']);
        $this->assertSame('req-1', $span->getData()['mcp.request.id']);
        $this->assertArrayNotHasKey('mcp.session.id', $span->getData());
    }

    #[Test]
    public function the_handshake_span_defaults_to_initialize(): void
    {
        McpSpan::initialize($this->transport, 1, ['name' => 'legacy', 'version' => '0.1'], fn (): bool => true);

        $span = $this->childSpans()[0];
        $this->assertSame('initialize', $span->getDescription());
        $this->assertSame('initialize', $span->getData()['mcp.method.name']);
        $this->assertSame('legacy', $span->getData()['client.name']);
        $this->assertSame('0.1', $span->getData()['client.version']);
    }

    #[Test]
    public function the_handshake_span_can_trace_server_discover(): void
    {
        McpSpan::initialize($this->transport, 1, ['name' => 'modern'], fn (): bool => true, 'server/discover');

        $span = $this->childSpans()[0];
        $this->assertSame('server/discover', $span->getDescription());
        $this->assertSame('server/discover', $span->getData()['mcp.method.name']);
        $this->assertSame('modern', $span->getData()['client.name']);
    }

    #[Test]
    public function spans_are_skipped_when_sentry_mcp_tracing_is_disabled(): void
    {
        config(['mcp.sentry.enabled' => false]);

        $result = McpSpan::toolCall('get-event', $this->transport, 1, [], fn (): string => 'done');

        $this->assertSame('done', $result);
        $this->assertSame([], $this->childSpans());
    }

    /**
     * @return array<int, Span>
     */
    private function childSpans(): array
    {
        return array_values(array_filter(
            $this->transaction->getSpanRecorder()->getSpans(),
            fn (Span $span): bool => $span !== $this->transaction,
        ));
    }
}
