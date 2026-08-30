<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads the vendored Flint skills from resources/ai/skills.
 *
 * The skill files are shared verbatim with the Claude Code Routines in
 * cronxco/claude; the extra manifest keys are ignored there, so one file
 * serves both runtimes.
 *
 * `allowed_tools` is a security boundary, not an optimisation. A skill run is
 * unattended, so `require_approval` is "never" and nothing sits between a
 * prompt-injected model and whatever the MCP token can reach. Every manifest is
 * therefore validated at load time, and the namespaces that can change
 * infrastructure are refused outright.
 */
class SkillRegistry
{
    /**
     * Tool namespaces a skill may never reach, regardless of what its manifest
     * asks for. These can deploy code, run SQL and rewrite network ACLs.
     *
     * @var array<int, string>
     */
    public const FORBIDDEN_NAMESPACES = ['komodo', 'supabase', 'tailscale'];

    /**
     * Namespaces a skill may reach.
     *
     * @var array<int, string>
     */
    public const ALLOWED_NAMESPACES = ['spark', 'karakeep', 'fastmail', 'docs', 'weather'];

    /** @var array<string, SkillDefinition>|null */
    private ?array $skills = null;

    /**
     * @return array<string, SkillDefinition>
     */
    public function all(): array
    {
        if ($this->skills === null) {
            $this->skills = [];

            foreach (File::glob($this->directory() . '/*.md') as $path) {
                $skill = $this->parse($path);
                $this->skills[$skill->name] = $skill;
            }

            ksort($this->skills);
        }

        return $this->skills;
    }

    public function get(string $name): SkillDefinition
    {
        $skill = $this->all()[$name] ?? null;

        if (! $skill) {
            throw new RuntimeException("Unknown Flint skill: {$name}");
        }

        return $skill;
    }

    public function has(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    private function directory(): string
    {
        return resource_path('ai/skills');
    }

    private function parse(string $path): SkillDefinition
    {
        $contents = File::get($path);
        $file = basename($path, '.md');

        if (! preg_match("/\A---\n(.*?)\n---\n(.*)\z/s", $contents, $matches)) {
            throw new RuntimeException("Skill {$file} has no YAML frontmatter.");
        }

        /** @var array<string, mixed> $meta */
        $meta = Yaml::parse($matches[1]) ?? [];
        $body = trim($matches[2]);

        if ($body === '') {
            throw new RuntimeException("Skill {$file} has an empty body.");
        }

        $name = $meta['name'] ?? $file;

        if ($name !== $file) {
            throw new RuntimeException("Skill {$file} declares a mismatched name '{$name}'.");
        }

        $model = AiModel::tryFrom($meta['model'] ?? '');

        if (! $model) {
            throw new RuntimeException("Skill {$file} declares an unknown model role.");
        }

        return new SkillDefinition(
            name: $name,
            description: trim((string) ($meta['description'] ?? '')),
            model: $model,
            allowedTools: $this->validateTools($file, $meta['allowed_tools'] ?? []),
            maxToolCalls: (int) ($meta['max_tool_calls'] ?? 0) ?: 40,
            timeoutSeconds: (int) ($meta['timeout_seconds'] ?? 0) ?: 300,
            body: $body,
        );
    }

    /**
     * @param  mixed  $tools
     * @return array<int, string>
     */
    private function validateTools(string $file, mixed $tools): array
    {
        if (! is_array($tools) || $tools === []) {
            throw new RuntimeException("Skill {$file} must declare at least one allowed tool.");
        }

        foreach ($tools as $tool) {
            if (! is_string($tool) || ! str_contains($tool, '__')) {
                throw new RuntimeException("Skill {$file} declares a malformed tool name.");
            }

            $namespace = strtok($tool, '__');

            if (in_array($namespace, self::FORBIDDEN_NAMESPACES, true)) {
                throw new RuntimeException(
                    "Skill {$file} declares the forbidden tool '{$tool}'. The {$namespace} namespace can change infrastructure and is never available to a skill."
                );
            }

            if (! in_array($namespace, self::ALLOWED_NAMESPACES, true)) {
                throw new RuntimeException("Skill {$file} declares the unrecognised tool namespace '{$namespace}'.");
            }
        }

        return array_values($tools);
    }
}
