<?php

namespace App\Mcp\Tools;

use App\Jobs\Flint\TriggerFlintDigestRoutineJob;
use App\Jobs\Flint\TriggerFlintRoutineJob;
use App\Mcp\Concerns\RequiresSparkAbility;
use App\Services\EffectiveTimezoneResolver;
use App\Services\Flint\RoutineConfig;
use App\Services\Flint\Routines\RoutineDriverManager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('run-flint-skill')]
class RunFlintSkillTool extends Tool
{
    use RequiresSparkAbility;

    protected string $description = <<<'MARKDOWN'
        Run a Flint routine now rather than waiting for its daily slot: `digest`,
        `topics`, `reading_list` or `news_roundup`. The routine runs through whichever
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

        $routine = (string) $request->get('routine');

        if (! RoutineConfig::isKnown($routine)) {
            return Response::error(
                "Unknown routine '{$routine}'. Known routines: " . implode(', ', RoutineConfig::ROUTINES) . '.'
            );
        }

        $timezones = app(EffectiveTimezoneResolver::class);
        $timezone = $timezones->timezoneFor($user);
        $date = $request->get('date') ?: $timezones->today($user)->toDateString();
        $period = (string) $request->get('period', 'morning');

        $job = $routine === 'digest'
            ? new TriggerFlintDigestRoutineJob($user, $period, $date, $timezone, 'manual', null, true)
            : new TriggerFlintRoutineJob($user, $routine, $date, $timezone, true);

        dispatch($job)->onQueue('flint');

        $driver = app(RoutineDriverManager::class)->driverName($routine);

        return Response::json([
            'routine' => $routine,
            'local_date' => $date,
            'driver' => $driver,
            'queued' => true,
            'message' => "Queued the {$routine} routine for {$date} via the {$driver} driver.",
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'routine' => $schema->string()
                ->description('Which routine to run: digest, topics, reading_list or news_roundup.')
                ->required(),

            'date' => $schema->string()
                ->description('Local date to run for (YYYY-MM-DD). Defaults to today in the user\'s effective timezone.'),

            'period' => $schema->string()
                ->description('Digest period when routine is "digest": morning, afternoon or evening. Defaults to morning.')
                ->default('morning'),
        ];
    }
}
