<?php

namespace App\Ai\Tools\AskFlint;

use App\Http\Requests\Api\V1\Mobile\StoreLogRequest;
use App\Models\Event;
use App\Services\Actions\ActionOutcome;
use App\Services\ManualLog\ManualLogService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * Logs something new through Manual Log, the one place Spark creates events
 * by hand, under the same rules as `POST /log`. So what Flint can create is
 * what Manual Log can: a wine, a beer, TV episodes, a cinema trip, a board
 * game or a reflection.
 *
 * One call is one change with one Undo, however many episodes it logs. A TV
 * title or season that could mean several things comes back as a question
 * for the user, and nothing is written until it is answered.
 */
class LogEventTool extends AskFlintWriteTool
{
    /** What a call may carry, as `StoreLogRequest` names them. */
    private const FIELDS = [
        'kind', 'title', 'body', 'occurred_at', 'rating', 'notes', 'quantity', 'unit',
        'serving', 'volume_ml', 'season', 'episodes', 'tvdb_id', 'with',
    ];

    public function name(): string
    {
        return 'log-event';
    }

    public function description(): string
    {
        return 'Log something the user says happened: a wine or beer they drank, TV episodes they watched, a film at the cinema, a board game they played, or a reflection (a free-text note about their day). One call per thing logged. If it comes back with status "needs_answer", ask the user that question with its options, then call again with the answer in the named field. Applies immediately; the app offers Undo.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'kind' => $schema->string()->enum(array_keys(ManualLogService::KINDS))->description('What is being logged.')->required(),
            'title' => $schema->string()->description('The wine, beer, show, film or game. Not used for a reflection.'),
            'body' => $schema->string()->description('The text of a reflection, in the user\'s words. Reflections only.'),
            'occurred_at' => $schema->string()->description('When it happened, ISO 8601. Without an offset it is read in the user\'s timezone. Omit for now. Never in the future.'),
            'rating' => $schema->number()->description('The user\'s rating, 1 to 5, only if they gave one.'),
            'notes' => $schema->string()->description('A short note the user gave about it.'),
            'quantity' => $schema->number()->description('How many (wine and beer).'),
            'unit' => $schema->string()->description('What the quantity counts, e.g. "glass" (wine and beer).'),
            'serving' => $schema->string()->description('Serving style for a beer, e.g. "pint", only if the user said.'),
            'volume_ml' => $schema->integer()->description('Serving size in millilitres, only if the user said.'),
            'season' => $schema->integer()->description('Season number (TV).'),
            'episodes' => $schema->array()->items($schema->integer())->description('Episode numbers watched (TV, required).'),
            'tvdb_id' => $schema->integer()->description('The series the user picked when asked which one (TV).'),
            'with' => $schema->array()->items($schema->string())->description('Names of people it was with.'),
        ];
    }

    protected function apply(Request $request): array
    {
        $data = collect($request->all())
            ->only(self::FIELDS)
            ->reject(fn (mixed $value): bool => $value === null || $value === '' || $value === [])
            ->all();

        if (isset($data['occurred_at'])) {
            try {
                $data['occurred_at'] = CarbonImmutable::parse((string) $data['occurred_at'], $this->user->getTimezone())->toIso8601String();
            } catch (Throwable) {
                return ['status' => 'failed', 'error' => 'occurred_at is not a date and time.'];
            }
        }

        $data['idempotency_key'] = (string) Str::uuid();

        $form = new StoreLogRequest;
        $validated = Validator::make($data, $form->rules(), $form->messages())->validate();

        try {
            $result = app(ManualLogService::class)->log($this->user, $validated);
        } catch (InvalidArgumentException $exception) {
            return ['status' => 'failed', 'error' => $exception->getMessage()];
        }

        if ($result['status'] === 'needs_clarification') {
            return [
                'status' => 'needs_answer',
                'question' => $result['question'],
                'answer_field' => $result['field'] === 'series' ? 'tvdb_id' : $result['field'],
                'options' => array_map(
                    fn (array $option): array => ['label' => $option['label'], 'value' => $option['value']],
                    $result['options'],
                ),
                'note' => 'Nothing was logged. Ask the user, then call again with their choice.',
            ];
        }

        /** @var array<int, Event> $events */
        $events = $result['events'];

        if ($events === []) {
            return ['status' => 'failed', 'error' => 'Nothing was logged.'];
        }

        $ids = array_map(fn (Event $event): string => (string) $event->id, $events);
        $receipt = $this->executor->record(
            $this->user,
            $this->action('flint.ask_log'),
            ['event_id' => $ids[0]],
            ActionOutcome::succeeded('Logged', ['event_ids' => $ids], [
                'event_ids' => $ids,
                'created_person_ids' => collect($result['people'] ?? [])->where('created', true)->pluck('id')->values()->all(),
            ]),
        );

        $missing = $result['missing_episodes'] ?? [];
        $summary = count($events) > 1 ? 'Logged ' . count($events) . ' episodes' : 'Logged';

        return $this->done($this->changes->add($receipt, 'create', 'event', $events[0], $summary)) + (
            $missing === [] ? [] : ['missing_episodes' => $missing, 'warning' => 'These episodes do not exist and were not logged. Tell the user.']
        );
    }
}
