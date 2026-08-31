<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresSparkAbility;
use App\Services\Flint\FlintRunDispatcher;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('run-flint-skill')]
class RunFlintSkillTool extends Tool
{
    use RequiresSparkAbility;

    protected string $description = <<<'MARKDOWN'
        Run a canonical Flint skill now rather than waiting for its daily slot. Deprecated
        routine aliases remain accepted. The skill runs through whichever
        driver it is configured for and writes its results back the usual way, so the
        digest or topics appear exactly as a scheduled run would leave them.

        Queued, so this returns as soon as the run is accepted. Requires `flint:run`.
    MARKDOWN;

    public function handle(Request $request): Response
    {
        if ($error = $this->requireAbility($request, 'flint:run')) {
            return $error;
        }

        $user = $request->user();

        if (! $user) {
            return Response::error('Authentication required.');
        }

        try {
            $result = app(FlintRunDispatcher::class)->dispatch(
                $user,
                skill: $request->get('skill'),
                routine: $request->get('routine'),
                date: $request->get('date'),
                period: $request->get('period', 'morning'),
            );

            return Response::json($result->toArray());
        } catch (InvalidArgumentException $exception) {
            return Response::error($exception->getMessage());
        }
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'skill' => $schema->string()
                ->description('Canonical skill: spark-day-briefing-async, flint-topics, flint-reading-list, or flint-news-roundup.'),

            'routine' => $schema->string()
                ->description('Deprecated routine alias: digest, topics, reading_list, or news_roundup.'),

            'date' => $schema->string()
                ->description('Local date to run for (YYYY-MM-DD). Defaults to today in the user\'s effective timezone.'),

            'period' => $schema->string()
                ->description('Digest period when routine is "digest": morning, afternoon or evening. Defaults to morning.')
                ->default('morning'),
        ];
    }
}
