<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\ResponsesSseDecoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ResponsesSseDecoderTest extends TestCase
{
    #[Test]
    public function it_decodes_frames_split_across_network_chunks(): void
    {
        $decoder = new ResponsesSseDecoder;

        $this->assertSame([], $decoder->push("event: response.output_item.added\ndata: {\"type\":\"response.mcp_"));

        $events = $decoder->push("call.in_progress\",\"name\":\"spark__create-flint-digest\"}\n\n", true);

        $this->assertSame('response.output_item.added', $events[0]['event']);
        $this->assertSame('response.mcp_call.in_progress', $events[0]['data']['type']);
        $this->assertSame('spark__create-flint-digest', $events[0]['data']['name']);
    }

    #[Test]
    public function it_decodes_crlf_frames_split_between_carriage_return_and_newline(): void
    {
        $decoder = new ResponsesSseDecoder;

        $this->assertSame([], $decoder->push("event: response.completed\r"));
        $events = $decoder->push("\ndata: {\"type\":\"response.completed\"}\r\n\r\n");

        $this->assertCount(1, $events);
        $this->assertSame('response.completed', $events[0]['event']);
        $this->assertSame('response.completed', $events[0]['data']['type']);
    }

    #[Test]
    public function malformed_sse_json_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('malformed SSE JSON');

        (new ResponsesSseDecoder)->push("data: {not-json}\n\n", true);
    }
}
