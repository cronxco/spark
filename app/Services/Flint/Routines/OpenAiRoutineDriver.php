<?php

namespace App\Services\Flint\Routines;

use App\Models\User;
use App\Services\Ai\SkillRegistry;
use App\Services\Ai\SkillRunner;
use Illuminate\Support\Facades\Log;

/**
 * Runs the routine's vendored skill in-process through the OpenAI Responses
 * API, with CronxTools attached. The skill writes its results back through the
 * same MCP tools the webhook driver's Claude Routine would have used.
 */
class OpenAiRoutineDriver implements RoutineDriver
{
    /** Routine name => the vendored skill that implements it. */
    private const SKILLS = [
        'digest' => 'spark-day-briefing-async',
        'topics' => 'flint-topics',
        'reading_list' => 'flint-reading-list',
        'news_roundup' => 'flint-news-roundup',
    ];

    public function __construct(
        private SkillRegistry $skills,
        private SkillRunner $runner,
    ) {}

    public function run(User $user, string $routine, array $payload): RoutineResult
    {
        $skillName = self::SKILLS[$routine] ?? null;

        if ($skillName === null || ! $this->skills->has($skillName)) {
            return RoutineResult::notApplicable("No vendored skill implements the {$routine} routine.");
        }

        if (empty(config('services.flint_routine.cronxtools_url'))) {
            return RoutineResult::notApplicable('No CronxTools MCP URL is configured.');
        }

        $result = $this->runner->run($this->skills->get($skillName), $payload);

        Log::info('Flint routine ran via OpenAI', [
            'user_id' => $user->id,
            'routine' => $routine,
        ] + $result->toArray());

        return RoutineResult::success(['driver' => 'openai'] + $result->toArray());
    }
}
