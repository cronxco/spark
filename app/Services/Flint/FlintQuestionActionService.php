<?php

namespace App\Services\Flint;

use App\Models\Block;
use App\Models\User;
use App\Services\Api\ResourceVersion;
use App\Support\FlintQuestion;
use App\Support\FlintQuestionPresenter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FlintQuestionActionService
{
    public function __construct(private ResourceVersion $versions) {}

    /**
     * @param  array{action:string, answer?:string|null, context?:string|null}  $input
     * @return array{status:int, data?:array<string,mixed>, message?:string, etag?:string}
     */
    public function record(User $user, string $blockId, array $input, ?string $ifMatch, string $mutationId): array
    {
        $owned = $this->ownedBlock($user, $blockId);

        if (! $owned) {
            return ['status' => 403, 'message' => 'Forbidden.'];
        }
        if (! FlintQuestion::isQuestion($owned)) {
            return ['status' => 422, 'message' => 'This block is not a user question.'];
        }

        $etag = $this->versions->etag($owned);
        if (! $ifMatch) {
            return ['status' => 428, 'message' => 'An If-Match header is required.', 'etag' => $etag];
        }

        return DB::transaction(function () use ($user, $blockId, $input, $ifMatch, $mutationId): array {
            $block = $this->ownedBlock($user, $blockId, lock: true);
            if (! $block) {
                return ['status' => 403, 'message' => 'Forbidden.'];
            }
            if (! FlintQuestion::isQuestion($block)) {
                return ['status' => 422, 'message' => 'This block is not a user question.'];
            }

            $normalized = $this->normalize($input);
            $requestHash = $this->requestHash($normalized);
            $existing = collect(FlintQuestion::history($block))
                ->first(fn (array $action) => ($action['client_mutation_id'] ?? null) === $mutationId);

            if ($existing) {
                $existingHash = $existing['request_hash'] ?? $this->requestHash([
                    'action' => $existing['action'],
                    'answer' => $existing['answer'] ?? null,
                    'context' => $existing['context'] ?? null,
                ]);

                if (! hash_equals($existingHash, $requestHash)) {
                    return ['status' => 409, 'message' => 'The idempotency key has already been used with different content.'];
                }

                return $this->success($block, 200);
            }

            if (! $this->versions->matches($block, $ifMatch)) {
                return [
                    'status' => 412,
                    'message' => 'The resource has changed. Refresh it and retry.',
                    'etag' => $this->versions->etag($block),
                ];
            }

            if ($message = $this->invalidTransition($block, $normalized['action'])) {
                return ['status' => 422, 'message' => $message];
            }

            $now = now()->toIso8601String();
            $metadata = $block->metadata ?? [];
            $history = FlintQuestion::history($block);
            $history[] = array_filter([
                'id' => $mutationId,
                'client_mutation_id' => $mutationId,
                'action' => $normalized['action'],
                'answer' => $normalized['answer'],
                'context' => $normalized['context'],
                'created_at' => $now,
                'request_hash' => $requestHash,
            ], fn (mixed $value) => $value !== null);
            $metadata['action_history'] = $history;

            if ($normalized['action'] === 'skip') {
                $metadata['question_status'] = 'skipped';
                $metadata['skipped_at'] = $now;
            } else {
                $metadata['answer'] = $normalized['answer'];
                $metadata['answer_note'] = $normalized['context'];
                $metadata['answered_at'] = $now;
                $metadata['question_status'] = 'answered';
            }

            $block->metadata = $metadata;
            $block->save();
            $block->event?->touch();

            return $this->success($block->fresh(['event']), 201);
        }, attempts: 3);
    }

    /** @return array{status:int, data?:array<string,mixed>, message?:string, etag?:string} */
    public function recordLegacy(User $user, string $blockId, string $answer, ?string $context): array
    {
        $block = $this->ownedBlock($user, $blockId);
        if (! $block) {
            return ['status' => 403, 'data' => [], 'etag' => ''];
        }

        return $this->record(
            $user,
            $blockId,
            [
                'action' => FlintQuestion::isAnswered($block) ? 'correct' : 'answer',
                'answer' => $answer,
                'context' => $context,
            ],
            $this->versions->etag($block),
            (string) Str::uuid(),
        );
    }

    private function ownedBlock(User $user, string $blockId, bool $lock = false): ?Block
    {
        return Block::query()
            ->whereKey($blockId)
            ->whereHas('event.integration', fn ($query) => $query->where('user_id', $user->id))
            ->with('event')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
    }

    /** @return array{action:string,answer:?string,context:?string} */
    private function normalize(array $input): array
    {
        return [
            'action' => $input['action'],
            'answer' => isset($input['answer']) ? trim((string) $input['answer']) : null,
            'context' => isset($input['context']) && trim((string) $input['context']) !== ''
                ? trim((string) $input['context'])
                : null,
        ];
    }

    private function requestHash(array $input): string
    {
        ksort($input);

        return hash('sha256', json_encode($input, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function invalidTransition(Block $block, string $action): ?string
    {
        $status = FlintQuestion::status($block);

        return match ($action) {
            'answer' => in_array($status, ['open', 'retired'], true) ? null : 'Only open or retired questions can be answered.',
            'correct' => FlintQuestion::isAnswered($block) ? null : 'A correction requires an existing answer.',
            'skip' => $status === 'open' ? null : 'Only open questions can be skipped.',
            default => 'Unsupported question action.',
        };
    }

    /** @return array{status:int, data:array<string,mixed>, etag:string} */
    private function success(Block $block, int $status): array
    {
        $etag = $this->versions->etag($block);

        return [
            'status' => $status,
            'data' => FlintQuestionPresenter::present($block),
            'etag' => $etag,
        ];
    }
}
