<?php

namespace Tests\Fixtures;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Fakes the OpenAI Responses API that laravel/ai's OpenAI provider calls, so
 * tests exercise the SDK's real request building and tool-calling loop.
 */
class FakeOpenAi
{
    public const URL = 'api.openai.com/v1/responses';

    /**
     * Answer successive Responses API calls with the given bodies, in order.
     *
     * @param  array<int, array<string, mixed>>  $bodies
     */
    public static function fake(array $bodies): void
    {
        $sequence = Http::sequence();
        foreach ($bodies as $body) {
            $sequence->push($body);
        }

        Http::fake([self::URL => $sequence]);
    }

    /** @param array{input_tokens?: int, output_tokens?: int} $usage */
    public static function text(?string $text, array $usage = ['input_tokens' => 10, 'output_tokens' => 5], string $id = 'resp_text'): array
    {
        return [
            'id' => $id,
            'model' => 'test-model',
            'status' => 'completed',
            'output' => [[
                'type' => 'message',
                'status' => 'completed',
                'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => $text ?? '', 'annotations' => []]],
            ]],
            'usage' => $usage,
        ];
    }

    /**
     * A step that asks for one or more function calls.
     *
     * @param  array<int, array{0: string, 1: array<string, mixed>}>  $calls  [name, arguments] pairs
     */
    public static function toolCalls(array $calls, array $usage = ['input_tokens' => 10, 'output_tokens' => 5], string $id = 'resp_tools'): array
    {
        $output = [];
        foreach (array_values($calls) as $i => [$name, $arguments]) {
            $output[] = [
                'type' => 'function_call',
                'id' => "fc_{$id}_{$i}",
                'call_id' => "call_{$id}_{$i}",
                'name' => $name,
                'arguments' => json_encode($arguments),
                'status' => 'completed',
            ];
        }

        return ['id' => $id, 'model' => 'test-model', 'status' => 'completed', 'output' => $output, 'usage' => $usage];
    }

    /** @return Collection<int, Request> the Responses API requests sent, in order */
    public static function requests(): Collection
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), self::URL))
            ->map(fn (array $pair) => $pair[0])
            ->values();
    }
}
