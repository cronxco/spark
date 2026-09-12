<?php

namespace App\Services;

use App\Integrations\Flint\FlintPlugin;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\Relationship;
use App\Models\User;
use App\Services\Flint\FlintRunToken;
use App\Services\Flint\RoutineConfig;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Creates the same Flint digest payload for REST and MCP callers. */
class FlintDigestService
{
    /** @return array<string, mixed> */
    public function create(User $user, array $input): array
    {
        $data = Validator::make($input, [
            'title' => ['required', 'string', 'max:255'],
            'period' => ['nullable', 'in:morning,afternoon,evening'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'run_token' => ['nullable', 'string', 'max:10000'],
            'summary' => ['nullable', 'string', 'max:10000'],
            'blocks' => ['nullable', 'array', 'max:50'],
            'blocks.*.block_type' => ['required', 'string', 'max:100', Rule::in(array_keys(FlintPlugin::getBlockTypes()))],
            'blocks.*.title' => ['required', 'string', 'max:255'],
            'blocks.*.content' => ['nullable', 'string', 'max:20000'],
            'blocks.*.url' => ['nullable', 'url', 'max:2048'],
            'blocks.*.minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'blocks.*.referenced_event_ids' => ['nullable', 'array', 'max:100'],
            'blocks.*.referenced_event_ids.*' => ['uuid'],
            'blocks.*.question' => ['nullable', 'string', 'max:1000'],
            'blocks.*.topic' => ['nullable', 'string', 'max:100'],
            'blocks.*.priority' => ['nullable', 'in:low,medium,high'],
            'blocks.*.answer_options' => ['nullable', 'array', 'max:20'],
            'blocks.*.answer_options.*' => ['string', 'max:255'],
            'blocks.*.day_context' => ['nullable', 'array'],
            'blocks.*.day_context.calendar' => ['nullable', 'array', 'max:20'],
            'blocks.*.day_context.calendar.*.title' => ['required_with:blocks.*.day_context.calendar', 'string', 'max:255'],
            'blocks.*.day_context.calendar.*.all_day' => ['nullable', 'boolean'],
            'blocks.*.day_context.calendar.*.start' => ['nullable', 'date'],
            'blocks.*.day_context.calendar.*.person' => ['nullable', 'in:will,dan'],
            'blocks.*.day_context.birthdays' => ['nullable', 'array', 'max:10'],
            'blocks.*.day_context.birthdays.*.title' => ['required_with:blocks.*.day_context.birthdays', 'string', 'max:255'],
            'blocks.*.day_context.weather' => ['nullable', 'array'],
            'blocks.*.day_context.weather.location' => ['nullable', 'string', 'max:255'],
            'blocks.*.day_context.weather.condition' => ['nullable', 'string', 'max:100'],
            'blocks.*.day_context.weather.temp_high_c' => ['nullable', 'numeric'],
            'blocks.*.day_context.weather.rain_probability_pct' => ['nullable', 'integer', 'min:0', 'max:100'],
        ], [
            // A block type the registry does not know renders as an unlabelled
            // grey card with no icon on every surface, silently. Three names
            // for the same news-story block appeared in one week before anyone
            // noticed, so say plainly what is allowed.
            'blocks.*.block_type.in' => 'Unknown Flint block type. Registered types are: '
                . implode(', ', array_keys(FlintPlugin::getBlockTypes())) . '.',
        ])->validate();

        $date = Carbon::parse(
            $data['date'] ?? now($user->getTimezone())->toDateString(),
            $user->getTimezone(),
        )->startOfDay();
        $period = $data['period'] ?? $this->inferPeriod();
        $run = isset($data['run_token'])
            ? app(FlintRunToken::class)->verify($data['run_token'], $user, $date->toDateString(), $period)
            : null;
        $sourceId = $run
            ? 'flint_digest_run:' . $run['run_uuid']
            : 'flint_digest:' . Str::uuid();
        $integration = $this->resolveIntegration($user);

        try {
            return DB::transaction(fn () => $this->createTransactionally(
                $user,
                $data,
                $date,
                $period,
                $sourceId,
                $integration,
                $run,
            ));
        } catch (UniqueConstraintViolationException $exception) {
            if (! $run) {
                throw $exception;
            }

            $event = Event::query()
                ->where('integration_id', $integration->id)
                ->where('source_id', $sourceId)
                ->with('blocks')
                ->firstOrFail();

            return $this->result($event, $period, true);
        }
    }

    public function resolveIntegration(User $user): Integration
    {
        return Integration::firstOrCreate(
            ['user_id' => $user->id, 'service' => 'flint', 'instance_type' => 'digest'],
            ['name' => 'Flint Digest'],
        );
    }

    /**
     * The EventObject a digest hangs off.
     *
     * Keyed on the routine as well as the period, because the once-daily
     * routines each declare a fixed period ('morning' for the news roundup,
     * 'evening' for the reading list and topic review) and would otherwise
     * collapse onto the day briefing's own object — rendering as a second,
     * competing section of that briefing rather than as their own artefact.
     * A conversational digest, or the day briefing itself, keeps the original
     * period-only key.
     */
    public function resolveDigestObject(User $user, string $period, Carbon $date, ?string $routine = null): EventObject
    {
        $ownObject = $routine !== null && $routine !== '' && $routine !== 'digest';

        return EventObject::firstOrCreate(
            [
                'user_id' => $user->id,
                'concept' => 'digest',
                'type' => ($ownObject ? $routine : $period) . '_digest',
                'title' => $date->format('Y-m-d') . ' ' . ($ownObject
                    ? strtoupper(str_replace('_', ' ', $routine))
                    : match ($period) {
                        'morning' => 'AM',
                        'afternoon' => 'PM',
                        default => 'EVE',
                    }),
            ],
            ['time' => now(), 'metadata' => array_filter([
                'service' => 'flint',
                'period' => $period,
                'routine' => $ownObject ? $routine : null,
                'generated_at' => now()->toIso8601String(),
            ], fn (mixed $value) => $value !== null)],
        );
    }

    /** @param array<string, mixed> $data @param array<string, mixed>|null $run */
    private function createTransactionally(
        User $user,
        array $data,
        Carbon $date,
        string $period,
        string $sourceId,
        Integration $integration,
        ?array $run,
    ): array {
        $existing = Event::query()
            ->where('integration_id', $integration->id)
            ->where('source_id', $sourceId)
            ->lockForUpdate()
            ->with('blocks')
            ->first();
        if ($existing) {
            return $this->result($existing, $period, true);
        }

        $digest = $this->resolveDigestObject($user, $period, $date, $run['routine'] ?? null);
        $actor = EventObject::firstOrCreate(
            ['user_id' => $user->id, 'concept' => 'user', 'type' => 'user_profile', 'title' => $user->name],
            ['time' => now()],
        );
        $blocks = $data['blocks'] ?? [];
        $metadata = array_filter([
            'period' => $period,
            'digest_object_id' => $digest->id,
            'title' => $data['title'],
            'summary' => $data['summary'] ?? null,
            'run_uuid' => $run['run_uuid'] ?? null,
            'routine' => $run['routine'] ?? null,
            // Derived from the verified run token rather than left for a
            // client to guess from the title. Null for a conversational
            // digest with no token, where the title sniff still applies.
            'kind' => RoutineConfig::digestKind($run['routine'] ?? null),
            'skill' => $run['skill'] ?? null,
            'trigger_source' => $run['trigger_source'] ?? null,
            'local_date' => $date->toDateString(),
        ], fn (mixed $value) => $value !== null);

        $event = Event::create([
            'source_id' => $sourceId,
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'service' => 'flint',
            'domain' => 'knowledge',
            'action' => 'had_summary',
            'time' => $date,
            'value' => count($blocks),
            'target_id' => $digest->id,
            'event_metadata' => $metadata,
        ]);
        Relationship::createRelationship([
            'user_id' => $user->id,
            'from_type' => Event::class,
            'from_id' => $event->id,
            'to_type' => EventObject::class,
            'to_id' => $digest->id,
            'type' => 'part_of',
        ]);

        foreach ($blocks as $block) {
            $blockMetadata = match (true) {
                $block['block_type'] === 'flint_user_question' => [
                    'question' => $block['question'] ?? $block['title'],
                    'topic' => $block['topic'] ?? null,
                    'priority' => $block['priority'] ?? 'medium',
                    'answer_options' => $block['answer_options'] ?? null,
                    'answer' => null,
                    'answer_note' => null,
                    'answered_at' => null,
                ],
                $block['block_type'] === 'flint_day_context' => [
                    'day_context' => $this->normalizeDayContext($block['day_context'] ?? []),
                ],
                default => [
                    'content' => $block['content'] ?? '',
                    'referenced_event_ids' => $block['referenced_event_ids'] ?? [],
                ],
            };
            // `url` and `minutes` belong on the block's own columns, not in
            // metadata — a reading pick's link is a link, and its length is a
            // value with a unit, so both render and sort without unpacking JSON.
            $event->createBlock(array_filter([
                'block_type' => $block['block_type'],
                'title' => $block['title'],
                'time' => $date,
                'url' => $block['url'] ?? null,
                'value' => $block['minutes'] ?? null,
                'value_unit' => isset($block['minutes']) ? 'minutes' : null,
                'metadata' => $blockMetadata,
            ], fn (mixed $value) => $value !== null));
        }

        return $this->result($event->load('blocks'), $period, false);
    }

    /** @return array<string, mixed> */
    private function result(Event $event, string $period, bool $deduplicated): array
    {
        return [
            'event_id' => $event->id,
            'digest_object_id' => $event->target_id,
            'date' => data_get($event->event_metadata, 'local_date', $event->time->toDateString()),
            'period' => $period,
            'title' => data_get($event->event_metadata, 'title'),
            'block_count' => $event->blocks->count(),
            'block_ids' => $event->blocks->pluck('id')->values()->all(),
            'deduplicated' => $deduplicated,
        ];
    }

    private function inferPeriod(): string
    {
        return match (true) {
            now()->hour <= 11 && now()->hour >= 5 => 'morning',
            now()->hour <= 16 => 'afternoon',
            default => 'evening',
        };
    }

    /**
     * Defaults a missing/invalid calendar entry's `person` to "will" server-side
     * rather than rejecting the whole digest write over one field the skill got
     * wrong — a validation failure here fails the entire routine run.
     *
     * @param  array<string, mixed>  $dayContext
     * @return array<string, mixed>
     */
    private function normalizeDayContext(array $dayContext): array
    {
        $calendar = collect($dayContext['calendar'] ?? [])
            ->map(fn (array $entry) => [
                'title' => $entry['title'] ?? '',
                'all_day' => (bool) ($entry['all_day'] ?? false),
                'start' => $entry['start'] ?? null,
                'person' => in_array($entry['person'] ?? null, ['will', 'dan'], true) ? $entry['person'] : 'will',
            ])
            ->values()
            ->all();

        $birthdays = collect($dayContext['birthdays'] ?? [])
            ->map(fn (array $entry) => ['title' => $entry['title'] ?? ''])
            ->values()
            ->all();

        return [
            'calendar' => $calendar,
            'birthdays' => $birthdays,
            'weather' => $dayContext['weather'] ?? null,
        ];
    }
}
