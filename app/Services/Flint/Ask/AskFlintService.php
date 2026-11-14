<?php

namespace App\Services\Flint\Ask;

use App\Ai\Agents\AskFlintAgent;
use App\Models\User;
use App\Services\Ai\AiModel;
use App\Services\Ai\AiTokenUsage;
use App\Services\Ai\AiUsageContext;
use App\Services\Ai\AiUsageRecorder;
use App\Services\Ai\AiUsageReservation;
use App\Services\Ai\Exceptions\AiDailyTokenCapExceeded;
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
     * The user's Ask Flint conversations, newest first.
     *
     * @return array{data: array<int, array<string, mixed>>, next_cursor: string|null, has_more: bool}
     */
    public function conversations(User $user, int $limit = 20, ?string $cursor = null): array
    {
        $page = Conversation::query()
            ->where('participant_type', Conversation::participantType($user))
            ->where('participant_id', (string) Conversation::participantKey($user))
            ->whereIn('id', ConversationMessage::query()->select('conversation_id')->where('agent', AskFlintAgent::class))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->cursorPaginate($limit, ['*'], 'cursor', $cursor);

        return [
            'data' => collect($page->items())->map(fn (Conversation $conversation): array => [
                'id' => $conversation->id,
                'title' => $conversation->title,
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

            foreach ($stream as $event) {
                if (! $announced && ($id = $stream->conversationId ?? $agent->currentConversation()) !== null) {
                    $announced = true;
                    yield ['conversation', ['conversation_id' => $id]];
                }

                if ($event instanceof TextDelta && $event->delta !== '') {
                    yield ['delta', ['text' => $event->delta]];
                } elseif ($event instanceof ToolCall) {
                    yield ['activity', ['tool' => $event->toolCall->name, 'label' => self::activityLabel($event->toolCall->name)]];
                } elseif ($event instanceof ToolResult) {
                    foreach (array_slice($changes->all(), $sent) as $change) {
                        $sent++;
                        yield ['change', $change];
                    }
                }
            }

            $this->settle($reservation, $stream->usage);
            $succeeded = true;

            yield ['done', [
                'conversation_id' => $stream->conversationId ?? $agent->currentConversation(),
                'message' => $this->transcript->fresh($user, $stream->assistantMessageId, (string) $stream->text, $changes->all()),
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
}
