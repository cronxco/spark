<?php

namespace App\Services\Ai;

use App\Mcp\Servers\SparkServer;
use ReflectionClass;
use ReflectionMethod;

/**
 * Checks the tool calls written into a skill's prose against the tools that
 * actually exist.
 *
 * A skill body is a prompt, so a call written against an imagined API fails at
 * runtime as an empty result rather than as an error — and a skill whose one
 * data-loading call cannot resolve still writes a fluent, confident digest
 * explaining that it found nothing. That is what happened to flint-news-roundup,
 * which asked `get-events-by-filter-tool` for a `domain` it has never accepted
 * and went two days reporting empty source coverage over six newsletters a day.
 *
 * Three checks, in increasing specificity:
 *
 * 1. every `namespace__tool` mentioned anywhere in the body is in
 *    {@see SkillRegistry::APPROVED_TOOLS};
 * 2. it is also in that skill's own `allowed_tools`, since the OpenAI driver
 *    enforces that list as a hard MCP allowlist — a call to a tool the manifest
 *    omits is silently unavailable at run time;
 * 3. for `spark__*` tools, every named argument at a call site is a parameter
 *    the tool's own `schema()` declares.
 *
 * Only the Spark namespace can be checked for parameters: the others are remote
 * MCP servers with no schema available in this process. Names are still checked
 * for all of them.
 */
class SkillToolLinter
{
    /** Matches a `namespace__tool-name` token. */
    private const TOOL = '[a-z]+__[a-z0-9_-]+';

    /** @var array<string, array<int, string>>|null tool name => parameter names */
    private ?array $sparkParameters = null;

    /**
     * @return array<int, string> human-readable problems, empty when the skill is clean
     */
    public function lint(SkillDefinition $skill): array
    {
        $problems = [];
        $allowed = $skill->allowedTools;
        $approved = SkillRegistry::APPROVED_TOOLS;

        foreach ($this->mentionedTools($skill->body) as $tool) {
            if (! in_array($tool, $approved, true)) {
                $problems[] = "references '{$tool}', which is not an approved tool";
            } elseif (! in_array($tool, $allowed, true)) {
                $problems[] = "references '{$tool}' but does not declare it in allowed_tools";
            }
        }

        foreach ($this->callSites($skill->body) as [$tool, $arguments]) {
            $parameters = $this->sparkParameters()[$tool] ?? null;

            if ($parameters === null) {
                continue;
            }

            $unknown = array_values(array_diff($arguments, $parameters));

            if ($unknown !== []) {
                sort($unknown);
                $problems[] = sprintf(
                    'calls %s with unknown argument%s %s (it accepts: %s)',
                    $tool,
                    count($unknown) === 1 ? '' : 's',
                    implode(', ', $unknown),
                    implode(', ', $parameters),
                );
            }
        }

        return array_values(array_unique($problems));
    }

    /**
     * Every distinct tool token in the body, whether called or merely named.
     *
     * @return array<int, string>
     */
    private function mentionedTools(string $body): array
    {
        preg_match_all('/\b(' . self::TOOL . ')/', $body, $matches);

        $tools = array_unique($matches[1]);
        sort($tools);

        return array_values($tools);
    }

    /**
     * Each `tool(argument: ..., argument: ...)` in the body, including calls
     * broken across lines.
     *
     * @return array<int, array{0: string, 1: array<int, string>}>
     */
    private function callSites(string $body): array
    {
        preg_match_all('/\b(' . self::TOOL . ')\s*\(/', $body, $matches, PREG_OFFSET_CAPTURE);

        $sites = [];

        foreach ($matches[1] as $index => [$tool, $_offset]) {
            $open = $matches[0][$index][1] + strlen($matches[0][$index][0]) - 1;
            $call = $this->balanced($body, $open);

            if ($call === null) {
                continue;
            }

            preg_match_all('/[(,\s]([a-z_]+):\s/', $call, $arguments);
            $sites[] = [$tool, array_values(array_unique($arguments[1]))];
        }

        return $sites;
    }

    /** The substring from an opening parenthesis to its match, or null when unbalanced. */
    private function balanced(string $body, int $open): ?string
    {
        $depth = 0;
        $length = strlen($body);

        for ($i = $open; $i < $length; $i++) {
            $depth += match ($body[$i]) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };

            if ($depth === 0) {
                return substr($body, $open, $i - $open + 1);
            }
        }

        return null;
    }

    /**
     * Parameter names for every Spark MCP tool, keyed by the name a skill would
     * write.
     *
     * The registered tool classes are the source of truth, so this cannot drift
     * from the running server. Laravel MCP derives a tool's name from its class
     * and the suffix is not applied consistently across this server
     * (`create-flint-digest` but `get-events-by-filter-tool`), so both spellings
     * are offered and whichever the approved list recognises wins.
     *
     * Nested object properties inside a schema are collected alongside the
     * top-level parameters. That makes the accepted set a superset, which can
     * miss a misplaced argument but never invents a false failure.
     *
     * @return array<string, array<int, string>>
     */
    private function sparkParameters(): array
    {
        if ($this->sparkParameters !== null) {
            return $this->sparkParameters;
        }

        $this->sparkParameters = [];

        foreach ($this->sparkToolClasses() as $class) {
            if (! method_exists($class, 'schema')) {
                continue;
            }

            $parameters = $this->declaredParameters(new ReflectionMethod($class, 'schema'));

            if ($parameters === []) {
                continue;
            }

            foreach ($this->candidateNames($class) as $name) {
                if (in_array($name, SkillRegistry::APPROVED_TOOLS, true)) {
                    $this->sparkParameters[$name] = $parameters;
                }
            }
        }

        return $this->sparkParameters;
    }

    /**
     * The tool classes registered on the Spark MCP server.
     *
     * @return array<int, class-string>
     */
    private function sparkToolClasses(): array
    {
        $tools = (new ReflectionClass(SparkServer::class))->getDefaultProperties()['tools'] ?? [];

        return is_array($tools) ? array_values(array_filter($tools, 'is_string')) : [];
    }

    /**
     * The parameter names a tool's `schema()` declares.
     *
     * Read from the method's own source rather than by invoking it: `schema()`
     * builds a tree of JsonSchema objects whose shape is the MCP package's
     * business, and the names are all this needs.
     *
     * @return array<int, string>
     */
    private function declaredParameters(ReflectionMethod $method): array
    {
        $file = $method->getFileName();

        if ($file === false || ! is_readable($file)) {
            return [];
        }

        $lines = array_slice(
            file($file, FILE_IGNORE_NEW_LINES) ?: [],
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        );

        preg_match_all("/'([a-z_]+)'\s*=>\s*\\\$schema->/", implode("\n", $lines), $matches);

        $parameters = array_unique($matches[1]);
        sort($parameters);

        return array_values($parameters);
    }

    /**
     * The names a tool class might be exposed under, most specific first.
     *
     * @param  class-string  $class
     * @return array<int, string>
     */
    private function candidateNames(string $class): array
    {
        $short = (new ReflectionClass($class))->getShortName();
        $stem = preg_replace('/Tool$/', '', $short) ?? $short;
        $kebab = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $stem));

        return ["spark__{$kebab}", "spark__{$kebab}-tool"];
    }
}
