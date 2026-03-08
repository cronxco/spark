<?php

declare(strict_types=1);

namespace App\Mcp\Tracing;

use Laravel\Mcp\Server\Contracts\Transport;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Throwable;

class McpSpan
{
    /**
     * Start a tool call span with MCP-specific attributes.
     *
     * @param  string  $toolName  The name of the tool being called (e.g., "SearchEvents")
     * @param  Transport  $transport  The transport instance for detecting transport type
     * @param  string|null  $requestId  The JSON-RPC request ID
     * @param  array  $arguments  The tool arguments (will be stringified per MCP spec)
     * @param  callable  $callback  The callback to execute within the span
     * @return mixed The result from the callback
     */
    public static function toolCall(
        string $toolName,
        Transport $transport,
        ?string $requestId,
        array $arguments,
        callable $callback
    ): mixed {
        if (! config('mcp.sentry.enabled', true)) {
            return $callback();
        }

        $parentSpan = SentrySdk::getCurrentHub()->getSpan();
        if (! $parentSpan) {
            return $callback();
        }

        $mcpTransport = TransportDetector::detect($transport);
        $networkTransport = TransportDetector::networkProtocol($mcpTransport);

        $context = new SpanContext;
        $span = $parentSpan->startChild($context);
        $span->setOp('mcp.server');
        $span->setDescription("tools/call {$toolName}");

        // Required MCP attributes
        $span->setData([
            'mcp.method.name' => 'tools/call',
            'mcp.transport' => $mcpTransport,
            'network.transport' => $networkTransport,
            'network.protocol.version' => '2.0',
            'mcp.tool.name' => $toolName,
        ]);

        // Optional attributes
        if ($requestId !== null) {
            $span->setData(['mcp.request.id' => $requestId]);
        }

        if ($transport->sessionId() !== null) {
            $span->setData(['mcp.session.id' => $transport->sessionId()]);
        }

        // Add stringified arguments (per MCP spec: only primitive types allowed)
        foreach ($arguments as $key => $value) {
            $stringified = self::stringifyValue($value);
            if ($stringified !== null) {
                $span->setData(["mcp.request.argument.{$key}" => $stringified]);
            }
        }

        SentrySdk::getCurrentHub()->setSpan($span);

        try {
            $result = $callback();

            // Mark as successful
            $span->setData(['mcp.tool.result.is_error' => false]);

            return $result;
        } catch (Throwable $e) {
            $span->setData(['mcp.tool.result.is_error' => true]);
            throw $e;
        } finally {
            $span->finish();
            SentrySdk::getCurrentHub()->setSpan($parentSpan);
        }
    }

    /**
     * Start a resource read span with MCP-specific attributes.
     *
     * @param  string  $resourceUri  The URI of the resource being read
     * @param  Transport  $transport  The transport instance for detecting transport type
     * @param  string|null  $requestId  The JSON-RPC request ID
     * @param  callable  $callback  The callback to execute within the span
     * @return mixed The result from the callback
     */
    public static function resourceRead(
        string $resourceUri,
        Transport $transport,
        ?string $requestId,
        callable $callback
    ): mixed {
        if (! config('mcp.sentry.enabled', true)) {
            return $callback();
        }

        $parentSpan = SentrySdk::getCurrentHub()->getSpan();
        if (! $parentSpan) {
            return $callback();
        }

        $mcpTransport = TransportDetector::detect($transport);
        $networkTransport = TransportDetector::networkProtocol($mcpTransport);

        $context = new SpanContext;
        $span = $parentSpan->startChild($context);
        $span->setOp('mcp.server');
        $span->setDescription("resources/read {$resourceUri}");

        // Required MCP attributes
        $span->setData([
            'mcp.method.name' => 'resources/read',
            'mcp.transport' => $mcpTransport,
            'network.transport' => $networkTransport,
            'network.protocol.version' => '2.0',
            'mcp.resource.uri' => $resourceUri,
        ]);

        // Extract resource name from URI
        $resourceName = self::extractResourceName($resourceUri);
        if ($resourceName !== null) {
            $span->setData(['mcp.resource.name' => $resourceName]);
        }

        // Optional attributes
        if ($requestId !== null) {
            $span->setData(['mcp.request.id' => $requestId]);
        }

        if ($transport->sessionId() !== null) {
            $span->setData(['mcp.session.id' => $transport->sessionId()]);
        }

        SentrySdk::getCurrentHub()->setSpan($span);

        try {
            $result = $callback();

            return $result;
        } finally {
            $span->finish();
            SentrySdk::getCurrentHub()->setSpan($parentSpan);
        }
    }

    /**
     * Start an initialize span with MCP-specific attributes.
     *
     * @param  Transport  $transport  The transport instance for detecting transport type
     * @param  string|null  $requestId  The JSON-RPC request ID
     * @param  array  $clientInfo  Client information from initialization
     * @param  callable  $callback  The callback to execute within the span
     * @return mixed The result from the callback
     */
    public static function initialize(
        Transport $transport,
        ?string $requestId,
        array $clientInfo,
        callable $callback
    ): mixed {
        if (! config('mcp.sentry.enabled', true)) {
            return $callback();
        }

        $parentSpan = SentrySdk::getCurrentHub()->getSpan();
        if (! $parentSpan) {
            return $callback();
        }

        $mcpTransport = TransportDetector::detect($transport);
        $networkTransport = TransportDetector::networkProtocol($mcpTransport);

        $context = new SpanContext;
        $span = $parentSpan->startChild($context);
        $span->setOp('mcp.server');
        $span->setDescription('initialize');

        // Required MCP attributes
        $span->setData([
            'mcp.method.name' => 'initialize',
            'mcp.transport' => $mcpTransport,
            'network.transport' => $networkTransport,
            'network.protocol.version' => '2.0',
        ]);

        // Optional attributes
        if ($requestId !== null) {
            $span->setData(['mcp.request.id' => $requestId]);
        }

        if ($transport->sessionId() !== null) {
            $span->setData(['mcp.session.id' => $transport->sessionId()]);
        }

        // Add client information
        if (isset($clientInfo['name'])) {
            $span->setData(['client.name' => $clientInfo['name']]);
        }

        if (isset($clientInfo['version'])) {
            $span->setData(['client.version' => $clientInfo['version']]);
        }

        SentrySdk::getCurrentHub()->setSpan($span);

        try {
            $result = $callback();

            return $result;
        } finally {
            $span->finish();
            SentrySdk::getCurrentHub()->setSpan($parentSpan);
        }
    }

    /**
     * Set session-level metadata tags for all subsequent spans.
     *
     * @param  array  $clientInfo  Client information from initialization
     * @param  string  $serverName  Server name
     * @param  string  $serverVersion  Server version
     * @param  string  $protocolVersion  MCP protocol version
     */
    public static function setSessionMetadata(
        array $clientInfo,
        string $serverName,
        string $serverVersion,
        string $protocolVersion
    ): void {
        if (! config('sentry.mcp.enabled', true)) {
            return;
        }

        SentrySdk::getCurrentHub()->configureScope(function ($scope) use ($clientInfo, $serverName, $serverVersion, $protocolVersion) {
            $scope->setTag('mcp.client.name', $clientInfo['name'] ?? 'unknown');
            $scope->setTag('mcp.client.version', $clientInfo['version'] ?? 'unknown');
            $scope->setTag('mcp.server.name', $serverName);
            $scope->setTag('mcp.server.version', $serverVersion);
            $scope->setTag('mcp.protocol.version', $protocolVersion);

            if (isset($clientInfo['title'])) {
                $scope->setTag('mcp.client.title', $clientInfo['title']);
            }
        });
    }

    /**
     * Stringify a value according to MCP span requirements.
     * MCP spec: only primitive data types allowed, complex objects must be stringified.
     *
     * @param  mixed  $value  The value to stringify
     * @param  int  $maxLength  Maximum length of stringified value
     * @return string|null Stringified value or null if it cannot be stringified
     */
    protected static function stringifyValue(mixed $value, int $maxLength = 1000): ?string
    {
        if (is_null($value)) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            $stringified = (string) $value;
        } else {
            $stringified = json_encode($value);
        }

        // Truncate if too long
        if (strlen($stringified) > $maxLength) {
            return substr($stringified, 0, $maxLength) . '... [truncated]';
        }

        return $stringified;
    }

    /**
     * Extract resource name from URI.
     *
     * @param  string  $uri  The resource URI (e.g., "spark://context/day/2026-03-07")
     * @return string|null The extracted resource name
     */
    protected static function extractResourceName(string $uri): ?string
    {
        // Parse URI to extract meaningful name
        if (preg_match('#^[^:]+://([^/]+)#', $uri, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
