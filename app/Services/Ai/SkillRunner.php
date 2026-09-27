<?php

namespace App\Services\Ai;

use App\Models\ActionProgress;
use App\Models\User;
use Exception;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Runs an unattended Flint skill through streamed OpenAI Responses + MCP. */
class SkillRunner
{
    /** The manifest namespace for tools served by the You.com MCP server. */
    public const YOU_NAMESPACE = 'you';
    private const ENDPOINT = 'https://api.openai.com/v1/responses';

    /** @param array<string, mixed> $payload */
    public function run(
        User $user,
        SkillDefinition $skill,
        array $payload,
        ?ActionProgress $progress = null,
        ?SkillContinuation $continuation = null,
    ): SkillRunResult {
        $serverUrl = config('services.flint_routine.cronxtools_url');
        if (! is_string($serverUrl) || $serverUrl === '') {
            throw new RuntimeException('No CronxTools MCP URL is configured; cannot run a Flint skill.');
        }
        $apiKey = config('services.openai.api_key');
        if (! is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException('No OpenAI API key is configured; cannot run a Flint skill.');
        }

        $reservation = app(AiUsageRecorder::class)->reserve(
            new AiUsageContext($user, 'agent', 'flint', skill: $skill->name),
            $skill->model->model(),
            null,
        );
        $agentSpan = start_ai_agent_span($skill->name, [
            'routine' => $payload['routine'] ?? null,
            'local_date' => $payload['local_date'] ?? null,
            'period' => $payload['period'] ?? null,
        ]);
        $agentSucceeded = false;
        $result = null;
        $providerResponded = false;

        try {
            $this->progress($progress, 'connecting', 'Connecting to OpenAI', 10);
            $this->progress($progress, 'discovering_tools', 'Discovering approved tools', 20);
            $request = [
                'model' => $skill->model->model(),
                'instructions' => $skill->body,
                'input' => json_encode($payload, JSON_THROW_ON_ERROR),
                'stream' => true,
                'max_tool_calls' => $skill->maxToolCalls,
                'tools' => $this->mcpServers($skill, $serverUrl),
            ];
            if ($continuation) {
                $request['previous_response_id'] = $continuation->responseId;
            }

            $response = Http::withToken($apiKey)
                ->timeout($skill->timeoutSeconds)
                ->connectTimeout(10)
                ->accept('text/event-stream')
                ->asJson()
                ->withOptions(['stream' => true])
                ->post(self::ENDPOINT, $request);
            $providerResponded = true;

            if (! $response->successful()) {
                throw new RuntimeException(
                    "Skill {$skill->name} failed: HTTP {$response->status()} " . $this->safeError($response->body())
                );
            }

            [$body, $streamFailures] = $this->decodeResponse($response, $progress);
            $result = $this->interpret($skill, $body, $streamFailures, $progress);

            try {
                app(AiUsageRecorder::class)->complete(
                    $reservation,
                    AiTokenUsage::fromArray($body['usage'] ?? []),
                    isset($body['id']) ? (string) $body['id'] : null,
                );
            } catch (Exception $accountingException) {
                // Never repeat a provider call after a successful response.
                report($accountingException);
            }

            $agentSucceeded = true;
            $this->progress($progress, 'completed', 'Flint routine completed', 100, [
                'tool_calls' => count($result->toolsCalled),
                'tools_called' => array_values(array_unique($result->toolsCalled)),
                'response_id' => $result->responseId,
            ]);

            return $result;
        } catch (Exception $exception) {
            if (! $providerResponded || ! $result) {
                try {
                    app(AiUsageRecorder::class)->fail($reservation);
                } catch (Exception $accountingException) {
                    report($accountingException);
                }
            }
            throw $exception;
        } finally {
            finish_ai_agent_span($agentSpan, $result?->toArray() ?? [], $agentSucceeded);
        }
    }

    /**
     * One MCP entry per server the skill needs. CronxTools serves every
     * namespaced tool under its own name; the You.com server names its tools
     * without our namespace, so `you__you-search` is offered as `you-search`.
     * Research is optional: with no You.com URL configured the skill still
     * runs, just without its search tool.
     *
     * @return array<int, array<string, mixed>>
     */
    private function mcpServers(SkillDefinition $skill, string $cronxToolsUrl): array
    {
        $cronxTools = [];
        $you = [];
        foreach ($skill->allowedTools as $tool) {
            if (str_starts_with($tool, self::YOU_NAMESPACE . '__')) {
                $you[] = substr($tool, strlen(self::YOU_NAMESPACE) + 2);
            } else {
                $cronxTools[] = $tool;
            }
        }

        $servers = [[
            'type' => 'mcp',
            'server_label' => 'cronxtools',
            'server_description' => 'Spark, Karakeep, Fastmail, Outline and weather tools.',
            'server_url' => $cronxToolsUrl,
            'allowed_tools' => $cronxTools,
            'require_approval' => 'never',
        ]];

        $youUrl = config('services.flint_routine.you_mcp_url');
        if ($you !== [] && is_string($youUrl) && $youUrl !== '') {
            $servers[] = [
                'type' => 'mcp',
                'server_label' => self::YOU_NAMESPACE,
                'server_description' => 'You.com web and news search, for developing stories already chosen from the user\'s own sources.',
                'server_url' => $youUrl,
                'allowed_tools' => $you,
                'require_approval' => 'never',
            ];
        }

        return $servers;
    }

    /**
     * The manifest name of a tool call, restoring the namespace the You.com
     * server does not carry so it can be checked against the allowlist.
     *
     * @param  array<string, mixed>  $item
     */
    private function qualifiedToolName(array $item): ?string
    {
        $name = $item['name'] ?? null;
        if (! is_string($name)) {
            return null;
        }

        return ($item['server_label'] ?? null) === self::YOU_NAMESPACE
            ? self::YOU_NAMESPACE . '__' . $name
            : $name;
    }

    /** @return array{0: array<string, mixed>, 1: array<int, string>} */
    private function decodeResponse(Response $response, ?ActionProgress $progress): array
    {
        $contentType = strtolower($response->header('Content-Type') ?? '');
        $terminal = null;
        $failures = [];
        $consume = function (array $events) use (&$terminal, &$failures, $progress): void {
            foreach ($events as $event) {
                $data = $event['data'];
                $type = (string) ($data['type'] ?? $event['event'] ?? '');
                if ($type === 'response.mcp_list_tools.in_progress') {
                    $this->progress($progress, 'discovering_tools', 'Discovering approved tools', 20);
                } elseif (str_ends_with($type, 'mcp_call.in_progress')) {
                    $name = (string) ($data['name'] ?? 'tool');
                    $this->progress($progress, 'tool_starting', "Running {$name}", 40, ['tool' => $name]);
                } elseif (str_ends_with($type, 'mcp_call.completed')) {
                    $name = (string) ($data['name'] ?? 'tool');
                    $this->progress($progress, 'tool_completed', "Completed {$name}", 70, ['tool' => $name]);
                }
                if (str_contains($type, 'mcp_') && str_ends_with($type, '.failed')) {
                    $failures[] = $type;
                }
                if ($type === 'response.completed') {
                    $terminal = $data['response'] ?? null;
                } elseif (in_array($type, ['response.failed', 'response.incomplete'], true)) {
                    $terminal = $data['response'] ?? null;
                    $failures[] = $type;
                }
            }
        };

        if (! str_contains($contentType, 'event-stream')) {
            $raw = $response->body();
            if (str_starts_with(ltrim($raw), '{')) {
                $body = json_decode($raw, true);
                if (! is_array($body)) {
                    throw new RuntimeException('OpenAI returned invalid response JSON.');
                }

                return [$body, []];
            }

            $consume((new ResponsesSseDecoder)->push($raw, true));
        } else {
            $decoder = new ResponsesSseDecoder;
            $stream = $response->toPsrResponse()->getBody();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            while (! $stream->eof()) {
                $consume($decoder->push($stream->read(8192)));
            }
            $consume($decoder->push('', true));
        }

        if (! is_array($terminal)) {
            throw new RuntimeException('OpenAI stream ended without a terminal response.');
        }

        return [$terminal, $failures];
    }

    /** @param array<string, mixed> $body @param array<int, string> $streamFailures */
    private function interpret(
        SkillDefinition $skill,
        array $body,
        array $streamFailures,
        ?ActionProgress $progress,
    ): SkillRunResult {
        if (($body['status'] ?? null) !== 'completed') {
            throw new RuntimeException("Skill {$skill->name} did not complete successfully.");
        }
        if (! empty($body['error']) || ! empty($body['incomplete_details']) || $streamFailures !== []) {
            throw new RuntimeException("Skill {$skill->name} returned a terminal or MCP stream error.");
        }

        $text = '';
        $toolsCalled = [];
        $successfulTools = [];
        $mcpListTools = [];
        $eventId = null;
        foreach ($body['output'] ?? [] as $item) {
            $type = $item['type'] ?? null;
            if ($type === 'mcp_list_tools') {
                if (! empty($item['error']) || (isset($item['status']) && $item['status'] !== 'completed')) {
                    throw new RuntimeException("Skill {$skill->name} failed MCP tool discovery.");
                }
                $mcpListTools[] = $item;

                continue;
            }
            if ($type === 'mcp_call') {
                $name = $this->qualifiedToolName($item);
                if (! is_string($name) || ! in_array($name, $skill->allowedTools, true)) {
                    throw new RuntimeException("Skill {$skill->name} invoked an unapproved tool.");
                }
                $toolsCalled[] = $name;
                $this->progress($progress, 'tool_starting', "Running {$name}", 40, ['tool' => $name]);
                $span = start_ai_tool_span($name);
                $succeeded = empty($item['error'])
                    && (! isset($item['status']) || $item['status'] === 'completed')
                    && ! $this->isApplicationError($item['output'] ?? null);
                try {
                    if (! $succeeded) {
                        throw new RuntimeException("Skill {$skill->name} tool {$name} failed.");
                    }
                    $successfulTools[] = $name;
                    if ($name === 'spark__create-flint-digest') {
                        $eventId = $this->eventIdFromOutput($item['output'] ?? null);
                    }
                    $this->progress($progress, 'tool_completed', "Completed {$name}", 70, ['tool' => $name]);
                } finally {
                    finish_ai_tool_span($span, $succeeded);
                }

                continue;
            }
            if ($type === 'message') {
                foreach ($item['content'] ?? [] as $content) {
                    $text .= $content['text'] ?? '';
                }
            }
        }

        foreach ($skill->requiredSuccessTools as $requiredTool) {
            if (! in_array($requiredTool, $successfulTools, true)) {
                throw new RuntimeException("Skill {$skill->name} did not complete required tool {$requiredTool}.");
            }
        }

        $usage = AiTokenUsage::fromArray($body['usage'] ?? []);
        $responseId = isset($body['id']) ? (string) $body['id'] : null;

        return new SkillRunResult(
            skill: $skill->name,
            text: trim($text),
            toolsCalled: $toolsCalled,
            inputTokens: $usage->inputTokens,
            outputTokens: $usage->outputTokens,
            responseId: $responseId,
            continuation: $responseId ? new SkillContinuation($responseId, $mcpListTools) : null,
            eventId: $eventId,
        );
    }

    private function eventIdFromOutput(mixed $output): ?string
    {
        $decoded = is_string($output) ? json_decode($output, true) : $output;
        if (! is_array($decoded)) {
            return null;
        }

        $eventId = $decoded['event_id'] ?? data_get($decoded, 'structuredContent.event_id');

        if (! is_string($eventId)) {
            foreach ($decoded['content'] ?? [] as $content) {
                if (! is_array($content) || ! is_string($content['text'] ?? null)) {
                    continue;
                }

                $nested = json_decode($content['text'], true);
                $eventId = is_array($nested)
                    ? ($nested['event_id'] ?? data_get($nested, 'structuredContent.event_id'))
                    : null;
                if (is_string($eventId)) {
                    break;
                }
            }
        }

        return is_string($eventId) ? $eventId : null;
    }

    private function isApplicationError(mixed $output): bool
    {
        if (is_array($output)) {
            if (($output['isError'] ?? false) === true
                || ($output['success'] ?? true) === false
                || ! empty($output['error'])) {
                return true;
            }

            foreach (is_array($output['content'] ?? null) ? $output['content'] : [] as $content) {
                if ($this->isApplicationError(is_array($content) ? ($content['text'] ?? $content) : $content)) {
                    return true;
                }
            }

            return false;
        }
        if (! is_string($output) || trim($output) === '') {
            return false;
        }
        $decoded = json_decode($output, true);
        if (is_array($decoded)) {
            return $this->isApplicationError($decoded);
        }

        return preg_match('/^\s*(?:error|failed|failure)\b/i', $output) === 1;
    }

    /** @param array<string, mixed> $details */
    private function progress(?ActionProgress $progress, string $step, string $message, int $percentage, array $details = []): void
    {
        $progress?->updateProgress($step, $message, $percentage, array_merge($progress->details ?? [], $details));
    }

    private function safeError(string $body): string
    {
        return mb_substr(redact_sensitive_urls($body), 0, 500);
    }
}
