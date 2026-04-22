<?php

namespace Tests\Unit\Mcp\Tracing;

use App\Mcp\Tracing\TransportDetector;
use Illuminate\Http\Request;
use Laravel\Mcp\Server\Transport\HttpTransport;
use Laravel\Mcp\Server\Transport\StdioTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TransportDetectorTest extends TestCase
{
    #[Test]
    public function detects_http_transport(): void
    {
        $transport = new HttpTransport(
            request: new Request,
            sessionId: 'test-session'
        );

        $result = TransportDetector::detect($transport);

        $this->assertEquals('http', $result);
    }

    #[Test]
    public function detects_stdio_transport(): void
    {
        $transport = new StdioTransport(sessionId: 'test-session');

        $result = TransportDetector::detect($transport);

        $this->assertEquals('stdio', $result);
    }

    #[Test]
    public function maps_http_to_tcp_network_protocol(): void
    {
        $result = TransportDetector::networkProtocol('http');

        $this->assertEquals('tcp', $result);
    }

    #[Test]
    public function maps_sse_to_tcp_network_protocol(): void
    {
        $result = TransportDetector::networkProtocol('sse');

        $this->assertEquals('tcp', $result);
    }

    #[Test]
    public function maps_stdio_to_pipe_network_protocol(): void
    {
        $result = TransportDetector::networkProtocol('stdio');

        $this->assertEquals('pipe', $result);
    }

    #[Test]
    public function maps_unknown_transport_to_unknown_network_protocol(): void
    {
        $result = TransportDetector::networkProtocol('unknown');

        $this->assertEquals('unknown', $result);
    }
}
