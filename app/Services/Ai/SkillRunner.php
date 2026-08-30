<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Runs a vendored Flint skill through the OpenAI Responses API, with the
 * CronxTools MCP server attached as a tool.
 *
 * OpenAI connects to the MCP server directly — Spark is not in that path — so
 * the skill writes its results back through the same MCP tools the Claude Code
 * Routines use. Nothing here parses a digest out of prose, which is what lets
 * one skill file work identically on both drivers.
 *
 * Runs are unattended, so `require_approval` is "never" and the skill's
 * `allowed_tools` is the only thing constraining what the model can reach.
 * SkillRegistry validates that list at load time.
 */
class SkillRunner
{
    private const ENDPOINT = 'https://api.openai.com/v1/responses';

    /**
     * @param  array<string, mixed>  $payload  Trigger context handed to the skill
     */
    public function run(SkillDefinition $skill, array $payload): SkillRunResult
    {
        $serverUrl = config('services.flint_routine.cronxtools_url');

        if (! is_string($serverUrl) || $serverUrl === '') {
            throw new RuntimeException('No CronxTools MCP URL is configured; cannot run a Flint skill.');
        }

        $apiKey = config('services.openai.api_key');

        if (! is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException('No OpenAI API key is configured; cannot run a Flint skill.');
        }

        $agentSpan = start_ai_agent_span($skill->name, [
            'routine' => $payload['routine'] ?? null,
            'local_date' => $payload['local_date'] ?? null,
            'period' => $payload['period'] ?? null,
        ]);

        // The tool block carries the MCP URL, and the URL carries the token.
        // It is deliberately never added to span data or logged.
        $response = Http::withToken($apiKey)
            ->timeout($skill->timeoutSeconds)
            ->connectTimeout(10)
            ->asJson()
            ->post(self::ENDPOINT, [
                'model' => $skill->model->model(),
                'instructions' => $skill->body,
                'input' => json_encode($payload),
                'tools' => [[
                    'type' => 'mcp',
                    'server_label' => 'cronxtools',
                    'server_description' => 'Spark, Karakeep, Fastmail, Outline and weather tools.',
                    'server_url' => $serverUrl,
                    'allowed_tools' => $skill->allowedTools,
                    'require_approval' => 'never',
                ]],
            ]);

        if (! $response->successful()) {
            finish_ai_agent_span($agentSpan, ['error' => $response->status()]);

            throw new RuntimeException(
                "Skill {$skill->name} failed: HTTP {$response->status()} " . $this->safeError($response->body())
            );
        }

        $result = $this->interpret($skill, $response->json() ?? []);
        finish_ai_agent_span($agentSpan, $result->toArray());

        return $result;
    }

    /**
     * Walk the response output, emitting a tool span per MCP call so a run's
     * tool use is visible in Sentry rather than opaque.
     *
     * @param  array<string, mixed>  $body
     */
    private function interpret(SkillDefinition $skill, array $body): SkillRunResult
    {
        $text = '';
        $toolsCalled = [];

        foreach ($body['output'] ?? [] as $item) {
            $type = $item['type'] ?? null;

            if ($type === 'mcp_call') {
                $name = $item['name'] ?? 'unknown';
                $toolsCalled[] = $name;

                $span = start_ai_tool_span($name, ['arguments' => $item['arguments'] ?? null]);
                finish_ai_tool_span($span, $item['error'] ?? $item['output'] ?? null);

                continue;
            }

            if ($type === 'message') {
                foreach ($item['content'] ?? [] as $content) {
                    $text .= $content['text'] ?? '';
                }
            }
        }

        $usage = $body['usage'] ?? [];

        return new SkillRunResult(
            skill: $skill->name,
            text: trim($text),
            toolsCalled: $toolsCalled,
            inputTokens: (int) ($usage['input_tokens'] ?? 0),
            outputTokens: (int) ($usage['output_tokens'] ?? 0),
        );
    }

    /**
     * An error body can echo the request, which contains the MCP URL.
     */
    private function safeError(string $body): string
    {
        return mb_substr(redact_sensitive_urls($body), 0, 500);
    }
}
