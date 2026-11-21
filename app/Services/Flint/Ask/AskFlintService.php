<?php

namespace App\Services\Flint\Ask;

use App\Ai\Agents\AskFlintAgent;
use App\Models\Event;
use App\Models\User;
use App\Services\Ai\AiModel;
use App\Services\Ai\AiTokenUsage;
use App\Services\Ai\AiUsageContext;
use App\Services\Ai\AiUsageRecorder;
use App\Services\Ai\AiUsageReservation;
use App\Services\Ai\Exceptions\AiDailyTokenCapExceeded;
use App\Services\Flint\FlintLinkedEventCards;
use App\Support\SparkAbility;
use Generator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\PaginatesConversations;
use Laravel\Ai\Contracts\VerifiesConversationOwnership;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Storage\StoredMessage;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Laravel\Sanctum\Sanctum;
use Throwable;

/**
 * Runs an Ask Flint turn and reads its conversations back.
 *
 * A turn runs as the user, holding a token that is never stored and carries
 * only what the calling token already allows from {@see ABILITIES}, so Flint
 * can never do more than the session that asked. Usage is reserved against
 * the daily token cap before the model is called and settled afterwards.
 */
class AskFlintService
{
    /**
     * The most Ask Flint may do on the user's behalf. Nothing that manages
     * tokens, integrations, notifications or runs routines.
     *
     * @var array<int, string>
     */
    public const ABILITIES = ['data:read', 'data:write', 'finance:read', 'flint:read', 'insights:read'];

    /** Reserved against the daily cap on top of the prompt, per turn. */
    private const RESERVED_TOKENS = 6000;

    private const TIMEOUT_SECONDS = 90;

    public function __construct(
        private AskFlintTools $tools,
        private AskFlintTranscript $transcript,
        private ConversationStore $store,
        private AiUsageRecorder $usage,
    ) {}

    /** The answer's opening sentence or two as plain text, for a list row. */
    public static function preview(string $markdown): ?string
    {
        $text = preg_replace(
            ['~\[([^\]]+)\]\([^)]*\)~', '~(?m)^\s{0,3}#{1,6}\s+~', '~(?m)^\s*(?:[-*+]|\d+[.)])\s+~', '~[*_`]{1,3}~', '~\s+~'],
            ['$1', '', '', '', ' '],
            $markdown,
        );
        $text = trim((string) $text);

        return $text === '' ? null : Str::limit($text, 140);
    }

    private static function activityLabel(string $tool): string
    {
        return match (true) {
            in_array($tool, AskFlintTools::writeToolNames(), true) => 'Making a change',
            str_contains($tool, 'search') => 'Searching Spark',
            str_contains($tool, 'day-summary') => 'Reading the day',
            str_contains($tool, 'metric') || str_contains($tool, 'baseline') => 'Checking metrics',
            str_contains($tool, 'money') => 'Checking money',
            default => 'Looking things up',
        };
    }

    /** @return array<int, mixed> */
    private static function storedSteps(mixed $steps): array
    {
        if (is_string($steps)) {
            $steps = json_decode($steps, true);
        }

        return is_array($steps) ? $steps : [];
    }

    /**
     * Ask and wait for the whole answer. `$readOnly` withholds the write
     * tools for this turn.
     *
     * @return array{conversation_id: string|null, message: array<string, mixed>}
     *
     * @throws AskFlintConversationNotFound
     * @throws AiDailyTokenCapExceeded
     */
    public function ask(User $user, string $message, ?string $conversationId = null, bool $readOnly = false): array
    {
        [$agent, $actor, $changes] = $this->prepare($user, $conversationId, $readOnly);
        $model = AiModel::Reasoning->model();
        $reservation = $this->reserve($user, $model, $message);
        $span = start_ai_agent_span('ask-flint', ['streaming' => false]);
        $succeeded = false;

        try {
            $response = $this->actingAs($actor, fn () => $agent->prompt(
                $message,
                provider: 'openai',
                model: $model,
                timeout: self::TIMEOUT_SECONDS,
            ));
            $this->settle($reservation, $response->usage);
            $succeeded = true;

            return [
                'conversation_id' => $response->conversationId ?? $agent->currentConversation(),
                'message' => $this->transcript->fresh($user, $response->assistantMessageId ?? null, $response->text, $changes->all()),
            ];
        } catch (Throwable $exception) {
            $succeeded || $this->abandon($reservation);

            throw $exception;
        } finally {
            finish_ai_agent_span($span, ['changes' => count($changes->all())], $succeeded);
        }
    }

    /**
     * Ask and receive the answer as it is written.
     *
     * Yields `[event, data]` frames: `conversation` once the thread has an
     * id, `delta` for text, `activity` when a tool starts, `change` for each
     * change made, then `done` with the finished message. A failure after
     * the stream has begun arrives as an `error` frame.
     *
     * @return Generator<int, array{0: string, 1: array<string, mixed>}>
     *
     * @throws AskFlintConversationNotFound
     * @throws AiDailyTokenCapExceeded
     */
    public function stream(User $user, string $message, ?string $conversationId = null, bool $readOnly = false): Generator
    {
        [$agent, $actor, $changes] = $this->prepare($user, $conversationId, $readOnly);
        $model = AiModel::Reasoning->model();
        $reservation = $this->reserve($user, $model, $message);

        return $this->frames($user, $actor, $agent, $changes, $reservation, $model, $message);
    }

    /**
     * The user's Ask Flint conversations, newest first, each with the opening
     * line of its latest answer, the domains of the events that answer links,
     * and how many changes Flint made in it. `$search` matches the title and
     * the text of any message.
     *
     * @return array{data: array<int, array<string, mixed>>, next_cursor: string|null, has_more: bool}
     */
    public function conversations(User $user, int $limit = 20, ?string $cursor = null, ?string $search = null): array
    {
        $askFlintMessages = fn () => ConversationMessage::query()->select('conversation_id')->where('agent', AskFlintAgent::class);
        $search = $search !== null ? trim($search) : null;
        $pattern = $search ? '%' . addcslashes($search, '%_\\') . '%' : null;

        $page = Conversation::query()
            ->where('participant_type', Conversation::participantType($user))
            ->where('participant_id', (string) Conversation::participantKey($user))
            ->whereIn('id', $askFlintMessages())
            ->when($pattern, fn ($query) => $query->where(fn ($query) => $query
                ->where('title', 'ilike', $pattern)
                ->orWhereIn('id', $askFlintMessages()->where('content', 'ilike', $pattern))))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->cursorPaginate($limit, ['*'], 'cursor', $cursor);

        $summaries = $this->summaries($user, collect($page->items())->pluck('id')->all());

        return [
            'data' => collect($page->items())->map(fn (Conversation $conversation): array => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'preview' => $summaries[$conversation->id]['preview'] ?? null,
                'domains' => $summaries[$conversation->id]['domains'] ?? [],
                'change_count' => $summaries[$conversation->id]['change_count'] ?? 0,
                'created_at' => $conversation->created_at?->toIso8601String(),
                'updated_at' => $conversation->updated_at?->toIso8601String(),
            ])->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
            'has_more' => $page->hasMorePages(),
        ];
    }

    /**
     * One conversation's messages, oldest first within the page. The cursor
     * pages backwards, towards the start of the thread.
     *
     * @return array{data: array<int, array<string, mixed>>, next_cursor: string|null, has_more: bool}
     *
     * @throws AskFlintConversationNotFound
     */
    public function messages(User $user, string $conversationId, int $limit = 30, ?string $cursor = null): array
    {
        $this->assertOwned($user, $conversationId);

        if (! $this->store instanceof PaginatesConversations) {
            return ['data' => [], 'next_cursor' => null, 'has_more' => false];
        }

        $page = $this->store->paginateConversationMessages($conversationId, $limit, 'cursor', $cursor);

        return [
            'data' => collect($page->items())
                ->reverse()
                ->map(fn (StoredMessage $message): array => $this->transcript->message($user, $message))
                ->values()
                ->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
            'has_more' => $page->hasMorePages(),
        ];
    }

    /** @throws AskFlintConversationNotFound */
    public function forget(User $user, string $conversationId): void
    {
        $this->assertOwned($user, $conversationId);

        ConversationMessage::query()->where('conversation_id', $conversationId)->delete();
        Conversation::query()->whereKey($conversationId)->delete();
    }

    /**
     * @param  array<int, string>  $conversationIds
     * @return array<string, array{preview: string|null, domains: array<int, string>, change_count: int}>
     */
    private function summaries(User $user, array $conversationIds): array
    {
        if ($conversationIds === []) {
            return [];
        }

        $answers = ConversationMessage::query()
            ->whereIn('conversation_id', $conversationIds)
            ->where('agent', AskFlintAgent::class)
            ->where('role', 'assistant')
            ->orderBy('created_at')
            ->get(['id', 'conversation_id', 'content', 'steps', 'created_at'])
            ->groupBy('conversation_id');

        $latest = $answers->map(fn ($messages) => AskFlintFollowUps::split((string) $messages->last()->content)['text']);

        $linked = $latest->map(fn (string $text): array => FlintLinkedEventCards::linkedIds($text));
        $domains = Event::query()
            ->whereIn('id', $linked->flatten()->unique()->values())
            ->whereIn('integration_id', $user->integrations()->pluck('id'))
            ->with('integration')
            ->get()
            ->mapWithKeys(fn (Event $event): array => [strtolower($event->id) => $event->domain]);

        $writeTools = AskFlintTools::writeToolNames();

        return $answers->map(fn ($messages, string $id): array => [
            'preview' => self::preview($latest[$id] ?? ''),
            'domains' => collect($linked[$id] ?? [])->map(fn (string $eventId): ?string => $domains[$eventId] ?? null)->filter()->unique()->values()->all(),
            'change_count' => $messages->sum(fn (ConversationMessage $message): int => collect(self::storedSteps($message->steps))
                ->flatMap(fn (mixed $step): array => is_array($step) ? (array) ($step['tool_calls'] ?? []) : [])
                ->filter(fn (mixed $call): bool => is_array($call) && in_array($call['name'] ?? null, $writeTools, true) && ! ($call['failed'] ?? false))
                ->count()),
        ])->all();
    }

    /**
     * A read-only turn is given no write tools, whatever the token allows:
     * it is how Siri asks, where no Undo can be shown.
     *
     * @return array{0: AskFlintAgent, 1: User, 2: AskFlintChangeLog}
     *
     * @throws AskFlintConversationNotFound
     */
    private function prepare(User $user, ?string $conversationId, bool $readOnly = false): array
    {
        if ($conversationId !== null) {
            $this->assertOwned($user, $conversationId);
        }

        $actor = $this->actor($user);
        $canWrite = ! $readOnly && SparkAbility::allows($actor, 'data:write');
        $changes = new AskFlintChangeLog;
        $timezone = $user->getTimezone();

        $agent = new AskFlintAgent(
            tools: $this->tools->for($actor, $changes, $canWrite),
            timezone: $timezone,
            now: now($timezone)->format('l j F Y, H:i'),
            canWrite: $canWrite,
            model: AiModel::Reasoning->model(),
        );
        $agent->continueOrStart($conversationId, $user);

        return [$agent, $actor, $changes];
    }

    /**
     * @return Generator<int, array{0: string, 1: array<string, mixed>}>
     */
    private function frames(
        User $user,
        User $actor,
        AskFlintAgent $agent,
        AskFlintChangeLog $changes,
        AiUsageReservation $reservation,
        string $model,
        string $message,
    ): Generator {
        $span = start_ai_agent_span('ask-flint', ['streaming' => true]);
        $succeeded = false;
        $guard = Auth::guard();
        $previous = $guard->hasUser() ? $guard->user() : null;
        $guard->setUser($actor);

        try {
            $stream = $agent->stream($message, provider: 'openai', model: $model, timeout: self::TIMEOUT_SECONDS);
            $announced = false;
            $sent = 0;
            $began = hrtime(true);
            /** @var array<string, array{step: array<string, mixed>, began: int}> $running */
            $running = [];
            /** @var array<int, array<string, mixed>> $steps */
            $steps = [];
            $durations = [];
            $pending = '';
            $heldBack = false;
            $reasoning = null;

            foreach ($stream as $event) {
                if (! $announced && ($id = $stream->conversationId ?? $agent->currentConversation()) !== null) {
                    $announced = true;
                    yield ['conversation', ['conversation_id' => $id]];
                }

                if ($event instanceof TextDelta && $event->delta !== '') {
                    if ($reasoning !== null) {
                        $steps[] = AskFlintSteps::reasoning($reasoning['id'], trim($reasoning['text']));
                        $reasoning = null;
                    }

                    if ($heldBack) {
                        continue;
                    }

                    $pending .= $event->delta;
                    $sendable = AskFlintFollowUps::sendable($pending);
                    if ($sendable > 0) {
                        yield ['delta', ['text' => substr($pending, 0, $sendable)]];
                        $pending = substr($pending, $sendable);
                    }
                    $heldBack = str_starts_with($pending, AskFlintFollowUps::OPEN);
                } elseif ($event instanceof ReasoningDelta) {
                    $delta = (string) ($event->delta ?? '');
                    if ($delta === '') {
                        continue;
                    }
                    $reasoning ??= ['id' => 'reasoning-' . count($steps), 'text' => ''];
                    $reasoning['text'] .= $delta;
                    yield ['reasoning', ['id' => $reasoning['id'], 'text' => $delta]];
                } elseif ($event instanceof ToolCall) {
                    if ($reasoning !== null) {
                        $steps[] = AskFlintSteps::reasoning($reasoning['id'], trim($reasoning['text']));
                        $reasoning = null;
                    }

                    $call = $event->toolCall;
                    $callId = (string) ($call->id ?? Str::uuid());
                    $step = AskFlintSteps::started($callId, $call->name, is_array($call->arguments ?? null) ? $call->arguments : []);
                    $running[$callId] = ['step' => $step, 'began' => hrtime(true)];

                    yield ['activity', ['tool' => $call->name, 'label' => self::activityLabel($call->name)]];
                    yield ['step', $step];
                } elseif ($event instanceof ToolResult) {
                    if ($event->preliminary ?? false) {
                        continue;
                    }

                    $result = $event->toolResult;
                    $callId = (string) ($result->id ?? '');
                    $started = $running[$callId] ?? null;

                    if ($started !== null) {
                        unset($running[$callId]);
                        $durations[$callId] = intdiv(hrtime(true) - $started['began'], 1_000_000);
                        $step = AskFlintSteps::finished($started['step'], $result->result ?? null, $durations[$callId], (bool) ($result->failed ?? false));
                        $steps[] = $step;
                        yield ['step', $step];
                    }

                    foreach (array_slice($changes->all(), $sent) as $change) {
                        $sent++;
                        yield ['change', $change];
                    }
                }
            }

            if (! $heldBack && $pending !== '') {
                yield ['delta', ['text' => $pending]];
            }

            $this->settle($reservation, $stream->usage);
            $succeeded = true;
            $elapsedMs = intdiv(hrtime(true) - $began, 1_000_000);
            $this->keepTimings($stream->assistantMessageId, $durations, $elapsedMs);

            yield ['done', [
                'conversation_id' => $stream->conversationId ?? $agent->currentConversation(),
                'message' => $this->transcript->fresh($user, $stream->assistantMessageId, (string) $stream->text, $changes->all(), $steps, $elapsedMs),
            ]];
        } catch (Throwable $exception) {
            report($exception);

            yield ['error', ['message' => 'Flint could not finish that answer. Any change already shown was made and can be undone.']];
        } finally {
            $succeeded || $this->abandon($reservation);
            finish_ai_agent_span($span, ['changes' => count($changes->all())], $succeeded);
            $previous ? $guard->setUser($previous) : $guard->forgetUser();
        }
    }

    /**
     * Keeps how long each step and the whole turn took on the stored answer,
     * so a reopened conversation shows the same trace. The SDK stores the
     * steps themselves; it has no timings.
     *
     * @param  array<string, int>  $durations  milliseconds by tool call id
     */
    private function keepTimings(?string $messageId, array $durations, int $elapsedMs): void
    {
        if ($messageId === null) {
            return;
        }

        try {
            $message = ConversationMessage::query()->whereKey($messageId)->first();
            if ($message === null) {
                return;
            }

            $stored = $message->meta;
            $meta = is_string($stored) ? (json_decode($stored, true) ?: []) : (is_array($stored) ? $stored : []);
            $meta[AskFlintTranscript::META_KEY] = ['elapsed_ms' => $elapsedMs, 'steps' => $durations];
            $message->forceFill(['meta' => is_string($stored) || $stored === null ? json_encode($meta) : $meta])->save();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * The user as this turn sees them: a copy holding a token that is never
     * stored and carries only the abilities the calling token already has.
     */
    private function actor(User $user): User
    {
        $tokenModel = Sanctum::personalAccessTokenModel();

        return (clone $user)->withAccessToken(new $tokenModel([
            'name' => 'ask-flint',
            'abilities' => array_values(array_filter(
                self::ABILITIES,
                fn (string $ability): bool => SparkAbility::allows($user, $ability),
            )),
        ]));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function actingAs(User $actor, callable $callback): mixed
    {
        $guard = Auth::guard();
        $previous = $guard->hasUser() ? $guard->user() : null;
        $guard->setUser($actor);

        try {
            return $callback();
        } finally {
            $previous ? $guard->setUser($previous) : $guard->forgetUser();
        }
    }

    /** @throws AskFlintConversationNotFound */
    private function assertOwned(User $user, string $conversationId): void
    {
        $owned = Str::isUuid($conversationId)
            && $this->store instanceof VerifiesConversationOwnership
            && $this->store->conversationBelongsTo(
                $conversationId,
                Conversation::participantType($user),
                Conversation::participantKey($user),
            );

        if (! $owned) {
            throw new AskFlintConversationNotFound('Conversation not found.');
        }
    }

    /** @throws AiDailyTokenCapExceeded */
    private function reserve(User $user, string $model, string $message): AiUsageReservation
    {
        return $this->usage->reserve(
            new AiUsageContext($user, 'ask_flint', 'flint'),
            $model,
            (int) ceil(strlen($message) / 4) + self::RESERVED_TOKENS,
        );
    }

    private function settle(AiUsageReservation $reservation, ?Usage $usage): void
    {
        try {
            $usage === null
                ? $this->usage->fail($reservation)
                : $this->usage->complete($reservation, AiTokenUsage::fromSdk($usage));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function abandon(AiUsageReservation $reservation): void
    {
        try {
            $this->usage->fail($reservation);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
