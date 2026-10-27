<?php

namespace App\Services\Ai;

use App\Ai\Agents\FlintSkillAgent;
use App\Ai\Tools\Skills\SkillTool;
use App\Ai\Tools\Skills\SkillToolLedger;
use App\Ai\Tools\Skills\SkillToolSource;
use App\Models\ActionProgress;
use App\Models\User;
use Closure;
use Exception;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Contracts\Tool;
use Laravel\Sanctum\Sanctum;
use RuntimeException;

/**
 * Runs an unattended Flint skill as a laravel/ai agent.
 *
 * Spark's own MCP tools (`spark__*`) run in-process, as the user, under a
 * short-lived token that carries only the capabilities skills need. The other
 * namespaces are reached through laravel/mcp's client: CronxTools for docs,
 * mail and weather, and You.com for research. Every tool is wrapped in a
 * {@see SkillTool}, which enforces the manifest, records the run and, in a dry
 * run, declines writes.
 */
class SkillRunner
{
    /** The manifest namespace for tools served by the You.com MCP server. */
    public const YOU_NAMESPACE = 'you';

    /** The manifest namespace for Spark's own tools, run in-process. */
    public const SPARK_NAMESPACE = 'spark';

    /**
     * Tools that change something outside the run. In a dry run each call to
     * one of these is recorded and declined, so the skill runs end to end
     * against live data without writing anything.
     *
     * @var array<int, string>
     */
    public const WRITE_TOOLS = [
        'docs__update_document',
        'spark__acknowledge-anomaly-tool',
        'spark__complete-flint-run',
        'spark__create-flint-digest',
        'spark__manage-flint-topic',
    ];

    /** manage-flint-topic operations that only read, run even in a dry run. */
    public const READ_ONLY_TOPIC_OPERATIONS = ['list', 'list_tasks'];

    /**
     * What the in-process Spark tools may do on the user's behalf: exactly the
     * capabilities the approved `spark__*` tools check, and nothing that
     * manages tokens or integrations.
     *
     * @var array<int, string>
     */
    public const SPARK_ABILITIES = [
        'data:read',
        'finance:read',
        'flint:read',
        'flint:write',
        'insights:read',
        'insights:write',
    ];

    public function __construct(private SkillToolSource $toolSource) {}

    /**
     * Whether a tool's output reports a failure, in any of the shapes MCP
     * servers and laravel/ai use for one.
     */
    public static function isApplicationError(mixed $output): bool
    {
        if (is_array($output)) {
            if (($output['isError'] ?? false) === true
                || ($output['success'] ?? true) === false
                || ! empty($output['error'])) {
                return true;
            }

            foreach (is_array($output['content'] ?? null) ? $output['content'] : [] as $content) {
                if (self::isApplicationError(is_array($content) ? ($content['text'] ?? $content) : $content)) {
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
            return self::isApplicationError($decoded);
        }

        return preg_match('/^\s*(?:MCP tool error|error|failed|failure)\b/i', $output) === 1;
    }

    /** The digest event id in a create-flint-digest result. */
    public static function eventIdFromOutput(mixed $output): ?string
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

    /** @param array<string, mixed> $payload */
    public function run(
        User $user,
        SkillDefinition $skill,
        array $payload,
        ?ActionProgress $progress = null,
        bool $dryRun = false,
    ): SkillRunResult {
        $serverUrl = config('services.flint_routine.cronxtools_url');
        if (! is_string($serverUrl) || $serverUrl === '') {
            throw new RuntimeException('No CronxTools MCP URL is configured; cannot run a Flint skill.');
        }
        $apiKey = config('services.openai.api_key');
        if (! is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException('No OpenAI API key is configured; cannot run a Flint skill.');
        }

        $model = $skill->model->model();
        $reservation = app(AiUsageRecorder::class)->reserve(
            new AiUsageContext($user, 'agent', 'flint', skill: $skill->name),
            $model,
            null,
        );
        $agentSpan = start_ai_agent_span($skill->name, [
            'routine' => $payload['routine'] ?? null,
            'local_date' => $payload['local_date'] ?? null,
            'period' => $payload['period'] ?? null,
            'dry_run' => $dryRun,
        ]);
        $agentSucceeded = false;
        $result = null;

        try {
            $ledger = new SkillToolLedger($skill->maxToolCalls, $dryRun, $progress);
            $ledger->progress('connecting', 'Connecting to OpenAI', 10);
            $ledger->progress('discovering_tools', 'Discovering approved tools', 20);
            $tools = $this->tools($skill, $serverUrl, $ledger);

            $response = $this->actingAs($user, fn () => (new FlintSkillAgent($skill, $tools))->prompt(
                json_encode($payload, JSON_THROW_ON_ERROR),
                provider: 'openai',
                model: $model,
                timeout: $skill->timeoutSeconds,
            ));

            if (! $dryRun) {
                foreach ($skill->requiredSuccessTools as $requiredTool) {
                    if (! in_array($requiredTool, $ledger->succeeded, true)) {
                        throw new RuntimeException("Skill {$skill->name} did not complete required tool {$requiredTool}.");
                    }
                }
            }

            $usage = AiTokenUsage::fromSdk($response->usage);
            $result = new SkillRunResult(
                skill: $skill->name,
                text: trim($response->text),
                toolsCalled: $ledger->called,
                inputTokens: $usage->inputTokens,
                outputTokens: $usage->outputTokens,
                responseId: $response->invocationId,
                eventId: $ledger->eventId,
                capturedWrites: $ledger->capturedWrites,
            );

            try {
                app(AiUsageRecorder::class)->complete($reservation, $usage, $result->responseId);
            } catch (Exception $accountingException) {
                // Never repeat a provider call after a successful response.
                report($accountingException);
            }

            $agentSucceeded = true;
            $ledger->progress('completed', 'Flint routine completed', 100, [
                'tool_calls' => count($result->toolsCalled),
                'tools_called' => array_values(array_unique($result->toolsCalled)),
                'tools_failed' => array_values(array_unique($ledger->failed)),
                'response_id' => $result->responseId,
            ]);

            return $result;
        } catch (Exception $exception) {
            if (! $result) {
                try {
                    app(AiUsageRecorder::class)->fail($reservation);
                } catch (Exception $accountingException) {
                    report($accountingException);
                }
            }

            throw new RuntimeException("Skill {$skill->name} failed: " . redact_sensitive_urls($exception->getMessage()), 0, $exception);
        } finally {
            $this->toolSource->disconnect();
            finish_ai_agent_span($agentSpan, $result?->toArray() ?? [], $agentSucceeded);
        }
    }

    /**
     * One {@see SkillTool} per tool the skill's manifest allows. A tool the
     * skill must succeed with but no server offers fails the run up front.
     *
     * @return array<int, SkillTool>
     */
    private function tools(SkillDefinition $skill, string $cronxToolsUrl, SkillToolLedger $ledger): array
    {
        $local = [];
        $cronxTools = [];
        $you = [];
        foreach ($skill->allowedTools as $tool) {
            match (true) {
                str_starts_with($tool, self::SPARK_NAMESPACE . '__') => $local[] = $tool,
                str_starts_with($tool, self::YOU_NAMESPACE . '__') => $you[] = $tool,
                default => $cronxTools[] = $tool,
            };
        }

        $tools = [];
        $sparkTools = $local === [] ? [] : $this->toolSource->spark();
        foreach ($local as $name) {
            if ($tool = $sparkTools[$this->unqualified($name)] ?? null) {
                $tools[$name] = $this->skillTool($skill, $name, $tool, $ledger);
            }
        }

        // CronxTools serves every namespaced tool under its own name.
        if ($cronxTools !== []) {
            foreach ($this->toolSource->remote($cronxToolsUrl, $cronxTools) as $name => $tool) {
                $tools[$name] = $this->skillTool($skill, $name, $tool, $ledger);
            }
        }

        // The You.com server names its tools without our namespace, so
        // `you__you-search` is offered there as `you-search`. Research is
        // optional: with no You.com URL configured the skill still runs.
        $youUrl = config('services.flint_routine.you_mcp_url');
        if ($you !== [] && is_string($youUrl) && $youUrl !== '') {
            $youKey = config('services.flint_routine.you_mcp_key');
            $remote = $this->toolSource->remote($youUrl, array_map($this->unqualified(...), $you), is_string($youKey) ? $youKey : null);
            foreach ($remote as $name => $tool) {
                $tools[self::YOU_NAMESPACE . '__' . $name] = $this->skillTool($skill, self::YOU_NAMESPACE . '__' . $name, $tool, $ledger);
            }
        }

        // Only what the manifest allows, whatever a server offered.
        $tools = array_intersect_key($tools, array_flip($skill->allowedTools));

        foreach ($skill->requiredSuccessTools as $required) {
            if (! isset($tools[$required])) {
                throw new RuntimeException("Skill {$skill->name} needs {$required}, which no MCP server offers.");
            }
        }

        return array_values($tools);
    }

    /**
     * Writes and the tools a skill must succeed with end the run when they
     * fail; any other tool's failure goes back to the model.
     */
    private function skillTool(SkillDefinition $skill, string $name, Tool $tool, SkillToolLedger $ledger): SkillTool
    {
        $mustSucceed = in_array($name, self::WRITE_TOOLS, true)
            || in_array($name, $skill->requiredSuccessTools, true);

        return new SkillTool($name, $tool, $ledger, $mustSucceed);
    }

    private function unqualified(string $tool): string
    {
        return substr($tool, strpos($tool, '__') + 2);
    }

    /**
     * Run the agent authenticated as the user, holding a token that is never
     * stored and carries only {@see SPARK_ABILITIES}, so the in-process tools
     * pass the same capability checks a CronxTools token would.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function actingAs(User $user, Closure $callback): mixed
    {
        $guard = Auth::guard();
        $previous = $guard->hasUser() ? $guard->user() : null;
        $tokenModel = Sanctum::personalAccessTokenModel();
        $actor = (clone $user)->withAccessToken(new $tokenModel([
            'name' => 'flint-skill',
            'abilities' => self::SPARK_ABILITIES,
        ]));
        $guard->setUser($actor);

        try {
            return $callback();
        } finally {
            $previous ? $guard->setUser($previous) : $guard->forgetUser();
        }
    }
}
