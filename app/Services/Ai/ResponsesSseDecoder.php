<?php

namespace App\Services\Ai;

use RuntimeException;

/** Incremental decoder for OpenAI Responses API server-sent events. */
class ResponsesSseDecoder
{
    private string $buffer = '';

    /** @return array<int, array{event: ?string, data: array<string, mixed>}> */
    public function push(string $chunk, bool $final = false): array
    {
        $this->buffer .= $chunk;
        $frames = preg_split('/(?:(?:\r\n|\r(?!\n)|\n)){2}/', $this->buffer) ?: [];

        if (! $final) {
            $this->buffer = array_pop($frames) ?? '';
        } else {
            $this->buffer = '';
        }

        $events = [];
        foreach ($frames as $frame) {
            $frame = str_replace(["\r\n", "\r"], "\n", $frame);
            if (trim($frame) === '') {
                continue;
            }

            $event = null;
            $dataLines = [];
            foreach (explode("\n", $frame) as $line) {
                if (str_starts_with($line, 'event:')) {
                    $event = trim(substr($line, 6));
                } elseif (str_starts_with($line, 'data:')) {
                    $dataLines[] = ltrim(substr($line, 5));
                }
            }

            $data = implode("\n", $dataLines);
            if ($data === '' || $data === '[DONE]') {
                continue;
            }

            $decoded = json_decode($data, true);
            if (! is_array($decoded)) {
                throw new RuntimeException('OpenAI returned malformed SSE JSON.');
            }

            $events[] = ['event' => $event, 'data' => $decoded];
        }

        return $events;
    }
}
