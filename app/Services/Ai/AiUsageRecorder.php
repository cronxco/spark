<?php

namespace App\Services\Ai;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Services\Ai\Exceptions\AiDailyTokenCapExceeded;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Atomically accounts OpenAI usage in internal Events. Reservations are
 * deliberately fail-closed: an abandoned reservation remains charged until
 * its local day expires.
 */
class AiUsageRecorder
{
    public function reserve(
        AiUsageContext $context,
        string $model,
        ?int $estimatedTokens,
        ?string $clientRequestId = null,
    ): AiUsageReservation {
        $timezone = $context->user->getTimezone();
        $localDate = now($timezone)->toDateString();
        $reservationId = (string) Str::uuid();
        $requestHash = $clientRequestId ? hash('sha256', $clientRequestId) : null;

        return DB::transaction(function () use ($context, $model, $estimatedTokens, $localDate, $reservationId, $requestHash) {
            $this->lockDay($context->user->id, $localDate);
            $this->lockIntegration($context->user->id);
            $integration = $this->resolveIntegration($context);
            $event = $this->lockOrCreateUsageEvent($context, $integration, $model, $localDate);

            $dayEvents = Event::query()
                ->where('service', 'openai')
                ->where('action', 'used_ai')
                ->whereHas('integration', fn ($query) => $query
                    ->where('user_id', $context->user->id)
                    ->where('instance_type', 'internal'))
                ->where('event_metadata->local_date', $localDate)
                ->lockForUpdate()
                ->get();

            $completed = (int) $dayEvents->sum(fn (Event $item) => (int) $item->value);
            $reserved = (int) $dayEvents->sum(function (Event $item): int {
                return collect(Arr::get($item->event_metadata ?? [], 'active_reservations', []))
                    ->sum(fn (mixed $reservation) => (int) ($reservation['tokens'] ?? 0));
            });
            $cap = max(0, (int) config('services.openai.daily_token_cap', 0));
            $remaining = $cap - $completed - $reserved;
            $tokens = max(0, (int) ($estimatedTokens ?? ($cap > 0 ? $remaining : 0)));

            if ($cap > 0 && ($remaining <= 0 || $tokens > $remaining)) {
                throw new AiDailyTokenCapExceeded('The daily OpenAI token allowance has been reached.');
            }

            $metadata = $event->event_metadata ?? [];
            $metadata['active_reservations'][$reservationId] = array_filter([
                'tokens' => $tokens,
                'operation' => $context->operation,
                'service' => $context->service,
                'skill' => $context->skill,
                'request_hash' => $requestHash,
                'reserved_at' => now()->toIso8601String(),
            ], fn (mixed $value) => $value !== null);
            $this->quietUpdate($event, ['event_metadata' => $metadata]);

            return new AiUsageReservation(
                id: $reservationId,
                eventId: $event->id,
                model: $model,
                localDate: $localDate,
                context: $context,
                requestHash: $requestHash,
            );
        });
    }

    public function complete(
        AiUsageReservation $reservation,
        AiTokenUsage|array $usage,
        ?string $clientRequestId = null,
    ): void {
        $usage = is_array($usage) ? AiTokenUsage::fromArray($usage) : $usage;
        $requestHash = $clientRequestId
            ? hash('sha256', $clientRequestId)
            : $reservation->requestHash;

        DB::transaction(function () use ($reservation, $usage, $requestHash): void {
            $this->lockDay($reservation->context->user->id, $reservation->localDate);
            $event = Event::query()->lockForUpdate()->findOrFail($reservation->eventId);
            $metadata = $event->event_metadata ?? [];
            if (! array_key_exists($reservation->id, $metadata['active_reservations'] ?? [])) {
                return;
            }
            unset($metadata['active_reservations'][$reservation->id]);

            if ($requestHash && in_array($requestHash, $metadata['recorded_requests'] ?? [], true)) {
                $this->quietUpdate($event, ['event_metadata' => $metadata]);

                return;
            }

            if ($requestHash) {
                $metadata['recorded_requests'][] = $requestHash;
                $metadata['recorded_requests'] = array_values(array_unique($metadata['recorded_requests']));
            }

            $totals = [
                'request_count' => 1,
                'input_tokens' => max(0, $usage->inputTokens),
                'output_tokens' => max(0, $usage->outputTokens),
                'total_tokens' => max(0, $usage->totalTokens()),
                'cached_tokens' => max(0, $usage->cachedTokens),
                'reasoning_tokens' => max(0, $usage->reasoningTokens),
            ];

            foreach ($totals as $key => $amount) {
                $metadata[$key] = (int) ($metadata[$key] ?? 0) + $amount;
            }

            $this->addBreakdown($metadata, 'operations', $reservation->context->operation, $totals);
            $this->addBreakdown($metadata, 'services', $reservation->context->service, $totals);
            if ($reservation->context->skill) {
                $this->addBreakdown($metadata, 'skills', $reservation->context->skill, $totals);
            }

            $this->quietUpdate($event, [
                'value' => (int) $event->value + $usage->totalTokens(),
                'event_metadata' => $metadata,
            ]);
        });
    }

    public function fail(AiUsageReservation $reservation): void
    {
        DB::transaction(function () use ($reservation): void {
            $this->lockDay($reservation->context->user->id, $reservation->localDate);
            $event = Event::query()->lockForUpdate()->findOrFail($reservation->eventId);
            $metadata = $event->event_metadata ?? [];
            if (! array_key_exists($reservation->id, $metadata['active_reservations'] ?? [])) {
                return;
            }
            unset($metadata['active_reservations'][$reservation->id]);
            $metadata['failure_count'] = (int) ($metadata['failure_count'] ?? 0) + 1;

            $failure = ['failure_count' => 1];
            $this->addBreakdown($metadata, 'operations', $reservation->context->operation, $failure);
            $this->addBreakdown($metadata, 'services', $reservation->context->service, $failure);
            if ($reservation->context->skill) {
                $this->addBreakdown($metadata, 'skills', $reservation->context->skill, $failure);
            }

            $this->quietUpdate($event, ['event_metadata' => $metadata]);
        });
    }

    private function lockDay(string $userId, string $localDate): void
    {
        /** @var ConnectionInterface $connection */
        $connection = DB::connection();
        if ($connection->getDriverName() === 'pgsql') {
            $connection->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["ai-usage:{$userId}:{$localDate}"]);
        }
    }

    private function lockIntegration(string $userId): void
    {
        /** @var ConnectionInterface $connection */
        $connection = DB::connection();
        if ($connection->getDriverName() === 'pgsql') {
            $connection->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["ai-usage-integration:{$userId}"]);
        }
    }

    private function resolveIntegration(AiUsageContext $context): Integration
    {
        $integration = Integration::query()
            ->where('user_id', $context->user->id)
            ->where('service', 'openai')
            ->where('instance_type', 'internal')
            ->lockForUpdate()
            ->first();

        if ($integration) {
            return $integration;
        }

        return Integration::withoutEvents(function () use ($context): Integration {
            $integration = new Integration;
            $integration->forceFill([
                'id' => (string) Str::uuid(),
                'user_id' => $context->user->id,
                'service' => 'openai',
                'instance_type' => 'internal',
                'name' => 'OpenAI Usage',
                'configuration' => ['internal' => true],
            ]);
            $integration->saveQuietly();

            return $integration;
        });
    }

    private function lockOrCreateUsageEvent(
        AiUsageContext $context,
        Integration $integration,
        string $model,
        string $localDate,
    ): Event {
        $sourceId = "ai_usage:{$localDate}:" . hash('sha256', $model);
        $event = Event::query()
            ->where('integration_id', $integration->id)
            ->where('source_id', $sourceId)
            ->lockForUpdate()
            ->first();

        if ($event) {
            return $event;
        }

        $day = $this->resolveObject(
            ['user_id' => $context->user->id, 'concept' => 'day', 'type' => 'day', 'title' => $localDate],
            ['time' => now($context->user->getTimezone())->startOfDay()->utc()],
        );
        $actor = $this->resolveObject(
            ['user_id' => $context->user->id, 'concept' => 'user', 'type' => 'user_profile', 'title' => $context->user->name],
            ['time' => now()],
        );

        return Event::withoutEvents(function () use ($sourceId, $integration, $actor, $day, $model, $localDate): Event {
            $event = new Event;
            $event->forceFill([
                'id' => (string) Str::uuid(),
                'source_id' => $sourceId,
                'time' => $day->time,
                'integration_id' => $integration->id,
                'actor_id' => $actor->id,
                'service' => 'openai',
                'domain' => 'online',
                'action' => 'used_ai',
                'value' => 0,
                'value_unit' => 'tokens',
                'target_id' => $day->id,
                'event_metadata' => [
                    'internal' => true,
                    'category' => 'ai_usage',
                    'provider' => 'openai',
                    'model' => $model,
                    'local_date' => $localDate,
                    'request_count' => 0,
                    'failure_count' => 0,
                    'input_tokens' => 0,
                    'output_tokens' => 0,
                    'total_tokens' => 0,
                    'cached_tokens' => 0,
                    'reasoning_tokens' => 0,
                    'operations' => [],
                    'services' => [],
                    'skills' => [],
                    'active_reservations' => [],
                    'recorded_requests' => [],
                ],
            ]);
            $event->saveQuietly();

            return $event;
        });
    }

    /** @param array<string, mixed> $identity @param array<string, mixed> $values */
    private function resolveObject(array $identity, array $values): EventObject
    {
        $object = EventObject::query()->where($identity)->first();
        if ($object) {
            return $object;
        }

        return EventObject::withoutEvents(function () use ($identity, $values): EventObject {
            $object = new EventObject;
            $object->forceFill(['id' => (string) Str::uuid()] + $identity + $values);
            $object->saveQuietly();

            return $object;
        });
    }

    /** @param array<string, mixed> $metadata @param array<string, int> $amounts */
    private function addBreakdown(array &$metadata, string $group, string $key, array $amounts): void
    {
        foreach ($amounts as $field => $amount) {
            $metadata[$group][$key][$field] = (int) ($metadata[$group][$key][$field] ?? 0) + $amount;
        }
    }

    /** @param array<string, mixed> $attributes */
    private function quietUpdate(Event $event, array $attributes): void
    {
        Event::withoutEvents(fn () => $event->forceFill($attributes)->saveQuietly());
    }
}
