<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\Relationship;
use App\Models\User;
use App\Services\Flint\FlintRunToken;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

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
            'blocks.*.block_type' => ['required', 'string', 'starts_with:flint_', 'max:100'],
            'blocks.*.title' => ['required', 'string', 'max:255'],
            'blocks.*.content' => ['nullable', 'string', 'max:20000'],
            'blocks.*.referenced_event_ids' => ['nullable', 'array', 'max:100'],
            'blocks.*.referenced_event_ids.*' => ['uuid'],
            'blocks.*.question' => ['nullable', 'string', 'max:1000'],
            'blocks.*.topic' => ['nullable', 'string', 'max:100'],
            'blocks.*.priority' => ['nullable', 'in:low,medium,high'],
            'blocks.*.answer_options' => ['nullable', 'array', 'max:20'],
            'blocks.*.answer_options.*' => ['string', 'max:255'],
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

    public function resolveDigestObject(User $user, string $period, Carbon $date): EventObject
    {
        return EventObject::firstOrCreate(
            [
                'user_id' => $user->id,
                'concept' => 'digest',
                'type' => $period . '_digest',
                'title' => $date->format('Y-m-d') . ' ' . match ($period) {
                    'morning' => 'AM',
                    'afternoon' => 'PM',
                    default => 'EVE',
                },
            ],
            ['time' => now(), 'metadata' => ['service' => 'flint', 'period' => $period, 'generated_at' => now()->toIso8601String()]],
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

        $digest = $this->resolveDigestObject($user, $period, $date);
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
            $blockMetadata = $block['block_type'] === 'flint_user_question'
                ? [
                    'question' => $block['question'] ?? $block['title'],
                    'topic' => $block['topic'] ?? null,
                    'priority' => $block['priority'] ?? 'medium',
                    'answer_options' => $block['answer_options'] ?? null,
                    'answer' => null,
                    'answer_note' => null,
                    'answered_at' => null,
                ]
                : [
                    'content' => $block['content'] ?? '',
                    'referenced_event_ids' => $block['referenced_event_ids'] ?? [],
                ];
            $event->createBlock([
                'block_type' => $block['block_type'],
                'title' => $block['title'],
                'time' => $date,
                'metadata' => $blockMetadata,
            ]);
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
}
