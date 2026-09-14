<?php

namespace App\Services\Flint;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Relationship;
use App\Models\User;
use App\Services\Api\ResourceVersion;
use App\Services\EffectiveTimezoneResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class FlintNoteService
{
    public const CONSENT_VERSION = 'flint-note-v1';

    public const RELATIONSHIP_TYPE = 'references';

    public function __construct(
        private EffectiveTimezoneResolver $timezones,
        private ResourceVersion $versions,
    ) {}

    /** @return array{status:int,note?:EventObject,message?:string} */
    public function create(User $user, array $input): array
    {
        $links = collect($input['context_links'] ?? [])
            ->map(fn (array $link) => ['type' => $link['type'], 'id' => strtolower($link['id'])])
            ->unique(fn (array $link) => $link['type'] . ':' . $link['id'])
            ->sortBy(fn (array $link) => $link['type'] . ':' . $link['id'])
            ->values()
            ->all();
        $normalized = [
            'authored_at' => CarbonImmutable::parse($input['authored_at'])->utc()->toIso8601String(),
            'body' => trim($input['body']),
            'context_links' => $links,
            'consent_version' => $input['consent_version'],
        ];
        $requestHash = hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $id = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'spark:flint-note:' . $user->id . ':' . strtolower($input['client_mutation_id']));
        $existing = EventObject::withTrashed()->where('user_id', $user->id)->find($id);

        if ($existing) {
            return hash_equals((string) data_get($existing->metadata, 'request_hash', ''), $requestHash)
                ? ['status' => 200, 'note' => $existing]
                : ['status' => 409, 'message' => 'The client mutation ID has already been used with different content.'];
        }

        $targets = [];
        foreach ($links as $link) {
            $target = $this->resolveContext($user, $link['type'], $link['id']);
            if (! $target) {
                return ['status' => 422, 'message' => "The {$link['type']} context link is invalid or is not owned by this account."];
            }
            $targets[] = [$link['type'], $target];
        }

        $result = DB::transaction(function () use ($user, $input, $normalized, $requestHash, $id, $targets): array {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['flint-note-mutation:' . $id]);
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['flint-note-title:' . $user->id . ':' . $normalized['authored_at']]);
            }

            $existing = EventObject::withTrashed()->where('user_id', $user->id)->find($id);
            if ($existing) {
                return hash_equals((string) data_get($existing->metadata, 'request_hash', ''), $requestHash)
                    ? ['status' => 200, 'note' => $existing]
                    : ['status' => 409, 'message' => 'The client mutation ID has already been used with different content.'];
            }

            $authoredAt = CarbonImmutable::parse($normalized['authored_at']);
            $title = $this->availableTitle($user, $authoredAt);
            $note = new EventObject;
            $note->id = $id;
            $note->fill([
                'user_id' => $user->id,
                'concept' => 'document',
                'type' => 'flint_note',
                'title' => $title,
                'content' => $normalized['body'],
                'time' => $authoredAt,
                'metadata' => [
                    'client_mutation_id' => strtolower($input['client_mutation_id']),
                    'request_hash' => $requestHash,
                    'consent_version' => $normalized['consent_version'],
                    'consented_at' => now()->toIso8601String(),
                    'effective_timezone' => $this->timezones->timezoneFor($user),
                ],
            ]);
            $note->save();

            foreach ($targets as [$apiType, $target]) {
                Relationship::createRelationship([
                    'user_id' => $user->id,
                    'from_type' => EventObject::class,
                    'from_id' => $note->id,
                    'to_type' => $target::class,
                    'to_id' => $target->id,
                    'type' => self::RELATIONSHIP_TYPE,
                    'metadata' => ['api_type' => $apiType],
                ]);
            }

            return ['status' => 201, 'note' => $note];
        }, attempts: 3);

        return $result;
    }

    public function delete(User $user, string $id): void
    {
        DB::transaction(function () use ($user, $id): void {
            $note = EventObject::withTrashed()
                ->where('user_id', $user->id)
                ->where('concept', 'document')
                ->where('type', 'flint_note')
                ->lockForUpdate()
                ->find($id);
            if (! $note || $note->trashed()) {
                return;
            }

            Relationship::query()
                ->where('user_id', $user->id)
                ->where('from_type', EventObject::class)
                ->where('from_id', $note->id)
                ->where('type', self::RELATIONSHIP_TYPE)
                ->get()
                ->each->delete();
            $note->delete();
        });
    }

    /** @return array<string, mixed> */
    public function payload(EventObject $note): array
    {
        $note->loadMissing(['relationshipsFrom' => fn ($query) => $query->where('type', self::RELATIONSHIP_TYPE)]);

        return [
            'id' => (string) $note->id,
            'title' => $note->title,
            'body' => $note->content,
            'authored_at' => $note->time?->toIso8601String(),
            'created_at' => $note->created_at?->toIso8601String(),
            'deleted_at' => $note->deleted_at?->toIso8601String(),
            'context_links' => $note->relationshipsFrom->map(fn (Relationship $relationship) => [
                'type' => data_get($relationship->metadata, 'api_type', $this->apiType($relationship)),
                'id' => (string) $relationship->to_id,
            ])->values()->all(),
            'consent_version' => data_get($note->metadata, 'consent_version'),
            'consented_at' => data_get($note->metadata, 'consented_at'),
            'version' => $this->versions->etag($note),
        ];
    }

    private function availableTitle(User $user, CarbonImmutable $authoredAt): string
    {
        $base = 'Note to Flint ' . $authoredAt->setTimezone($this->timezones->timezoneFor($user))->format('d/m/y H:i');
        $titles = EventObject::query()
            ->where('user_id', $user->id)
            ->where('concept', 'document')
            ->where('type', 'flint_note')
            ->where(fn ($query) => $query->where('title', $base)->orWhere('title', 'like', $base . ' (%)'))
            ->pluck('title')
            ->flip();

        if (! $titles->has($base)) {
            return $base;
        }

        for ($suffix = 2; ; $suffix++) {
            $candidate = "{$base} ({$suffix})";
            if (! $titles->has($candidate)) {
                return $candidate;
            }
        }
    }

    private function resolveContext(User $user, string $type, string $id): ?Model
    {
        return match ($type) {
            'event' => Event::query()->whereHas('integration', fn ($query) => $query->where('user_id', $user->id))->find($id),
            'digest' => Event::query()->where('service', 'flint')->where('action', 'had_summary')->whereHas('integration', fn ($query) => $query->where('user_id', $user->id))->find($id),
            'block' => Block::query()->whereHas('event.integration', fn ($query) => $query->where('user_id', $user->id))->find($id),
            'topic' => EventObject::query()->where('user_id', $user->id)->where('concept', 'flint')->where('type', 'topic')->find($id),
            default => null,
        };
    }

    private function apiType(Relationship $relationship): string
    {
        return match ($relationship->to_type) {
            Block::class => 'block',
            Event::class => 'event',
            EventObject::class => 'topic',
            default => 'unknown',
        };
    }
}
