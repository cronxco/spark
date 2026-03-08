<?php

declare(strict_types=1);

namespace App\Mcp\Tracing;

use Laravel\Mcp\Server\Contracts\Transport;
use Laravel\Mcp\Server\Transport\HttpTransport;
use Laravel\Mcp\Server\Transport\StdioTransport;

class TransportDetector
{
    /**
     * Detect the MCP transport type from a Transport instance.
     *
     * @return string "http", "stdio", or "unknown"
     */
    public static function detect(Transport $transport): string
    {
        return match (true) {
            $transport instanceof HttpTransport => 'http',
            $transport instanceof StdioTransport => 'stdio',
            default => 'unknown',
        };
    }

    /**
     * Map MCP transport type to OSI layer network protocol.
     *
     * @return string "tcp", "pipe", or "unknown"
     */
    public static function networkProtocol(string $mcpTransport): string
    {
        return match ($mcpTransport) {
            'http', 'sse' => 'tcp',
            'stdio' => 'pipe',
            default => 'unknown',
        };
    }
}
