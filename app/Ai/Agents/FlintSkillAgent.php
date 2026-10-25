<?php

namespace App\Ai\Agents;

use App\Services\Ai\SkillDefinition;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;

/**
 * Runs one vendored Flint skill: the skill body is the instructions and the
 * tools are exactly the ones its manifest allows.
 */
class FlintSkillAgent implements Agent, HasTools
{
    use Promptable;

    /** @param array<int, Tool> $tools */
    public function __construct(
        private SkillDefinition $skill,
        private array $tools,
    ) {}

    public function instructions(): string
    {
        return $this->skill->body;
    }

    public function tools(): iterable
    {
        return $this->tools;
    }

    /** One step per allowed tool call, plus the step that writes the answer. */
    public function maxSteps(): int
    {
        return $this->skill->maxToolCalls + 1;
    }
}
