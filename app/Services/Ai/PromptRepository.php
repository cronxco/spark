<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Loads system prompts from resources/ai/prompts.
 *
 * Prompts live as files rather than heredocs so a given prompt exists exactly
 * once, is reviewable as prose in a diff, and can be compared across versions
 * when output quality changes.
 */
class PromptRepository
{
    /** @var array<string, string> */
    private array $cache = [];

    /**
     * @param  string  $key  Path under resources/ai/prompts, without extension
     *
     * @throws RuntimeException when the prompt file is missing
     */
    public function get(string $key): string
    {
        if (! isset($this->cache[$key])) {
            $path = resource_path('ai/prompts/' . $key . '.md');

            if (! File::exists($path)) {
                throw new RuntimeException("AI prompt not found: {$key}");
            }

            $this->cache[$key] = rtrim(File::get($path), "\n");
        }

        return $this->cache[$key];
    }
}
