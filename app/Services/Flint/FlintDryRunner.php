<?php

namespace App\Services\Flint;

use App\Models\User;
use App\Services\Ai\SkillRegistry;
use App\Services\Ai\SkillRunner;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Runs a Flint skill against live data without letting it write anything.
 *
 * The skill reads exactly as a real run would, but every write tool call
 * (the digest, topic edits, run completion, the Outline day note) is held,
 * recorded and declined by {@see SkillRunner}. Nothing goes through the
 * trigger jobs, so run history, markers and notifications are untouched.
 * The would-be writes and the run's own notes land in one JSON file, which
 * is what an eval compares across models.
 *
 * Only the openai driver can do this: a Claude Code Routine writes through
 * its own MCP connection, which Spark cannot intercept.
 */
class FlintDryRunner
{
    public const DIRECTORY = 'flint-dry-runs';

    public function __construct(
        private FlintRunDispatcher $dispatcher,
        private SkillRegistry $skills,
        private SkillRunner $runner,
        private FlintRunToken $tokens,
    ) {}

    /** @return array{path: string, record: array<string, mixed>} */
    public function run(User $user, mixed $skill, mixed $date = null, mixed $period = 'morning', mixed $driver = null): array
    {
        if ($driver !== null && $driver !== 'openai') {
            throw new InvalidArgumentException('A dry run needs the openai driver; a webhook routine writes through its own connection.');
        }
        $run = $this->dispatcher->resolve($user, $skill, null, $date, $period, 'openai');
        if (! $this->skills->has($run->skill)) {
            throw new InvalidArgumentException("No vendored skill implements {$run->skill}.");
        }

        $runUuid = (string) Str::uuid();
        $payload = [
            'user_id' => (string) $user->id,
            'routine' => $run->routine,
            'local_date' => $run->localDate,
            'timezone' => $run->timezone,
            'period' => $run->period,
            'idempotency_key' => "flint:dry-run:{$runUuid}",
            'run_token' => $this->tokens->issue([
                'run_uuid' => $runUuid,
                'user_id' => (string) $user->id,
                'routine' => $run->routine,
                'skill' => $run->skill,
                'local_date' => $run->localDate,
                'period' => $run->period,
                'trigger_source' => 'dry_run',
                'dry_run' => true,
            ]),
            'dry_run' => true,
        ];
        if ($run->routine === 'digest') {
            $payload['trigger_reason'] = 'manual';
            $payload['sleep_score_event_id'] = null;
        }

        $result = $this->runner->run($user, $this->skills->get($run->skill), $payload, dryRun: true);

        $record = [
            'run_uuid' => $runUuid,
            'skill' => $run->skill,
            'routine' => $run->routine,
            'local_date' => $run->localDate,
            'period' => $run->period,
            'driver' => 'openai',
            'model' => RoutineModel::for('openai'),
            'created_at' => now()->toIso8601String(),
            'writes' => $result->capturedWrites,
            'text' => $result->text,
        ] + $result->toArray();

        $path = self::DIRECTORY . "/{$run->localDate}-{$run->period}-{$run->routine}-{$runUuid}.json";
        Storage::disk('local')->put($path, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return ['path' => $path, 'record' => $record];
    }
}
