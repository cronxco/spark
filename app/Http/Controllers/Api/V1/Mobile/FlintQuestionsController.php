<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Block;
use App\Services\EffectiveTimezoneResolver;
use App\Services\Flint\FlintQuestionActionService;
use App\Support\FlintQuestion;
use App\Support\FlintQuestionPresenter;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class FlintQuestionsController extends Controller
{
    /** Every status FlintQuestion::status() can return, and so every value `status` accepts. */
    private const ALLOWED_STATUSES = ['open', 'answered', 'skipped', 'retired'];

    /**
     * GET /api/v1/mobile/flint/questions?status=open,answered&since=48h
     *
     * `status` takes a comma-separated list so a client showing "the last 48
     * hours of questions, open or answered" doesn't have to make two
     * unbounded requests and filter locally — `since` (an ISO timestamp, or a
     * relative window like `48h`/`7d`) bounds the query server-side instead.
     */
    public function index(Request $request, EffectiveTimezoneResolver $timezones): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string'],
            'since' => ['nullable', 'string', 'max:64'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'cursor' => ['nullable', 'string'],
        ]);
        $limit = (int) ($validated['limit'] ?? 20);

        $statuses = isset($validated['status'])
            ? array_values(array_filter(array_map('trim', explode(',', $validated['status']))))
            : ['open'];
        $unknown = array_diff($statuses, self::ALLOWED_STATUSES);
        if ($statuses === [] || $unknown !== []) {
            return response()->json([
                'message' => 'Invalid status. Allowed values: ' . implode(', ', self::ALLOWED_STATUSES) . '.',
            ], 422);
        }

        $since = null;
        if (isset($validated['since'])) {
            $since = $this->parseSince($validated['since'], $timezones->now($request->user()));
            if ($since === null) {
                return response()->json(['message' => 'Invalid since. Use an ISO timestamp or a relative window like "48h" or "7d".'], 422);
            }
        }

        $query = Block::query()
            ->select(['blocks.*', 'xmin'])
            ->where('block_type', FlintQuestion::BLOCK_TYPE)
            ->whereHas('event.integration', fn ($query) => $query->where('user_id', $request->user()->id))
            ->with('event');

        if ($since !== null) {
            $query->where('time', '>=', $since);
        } elseif ($statuses === ['open']) {
            // Preserves the original default: an open-only list is naturally
            // bounded by the retirement horizon, so the badge doesn't grow
            // forever when no explicit window was asked for.
            $query->where('time', '>=', $timezones->now($request->user())->subDays(FlintQuestion::RETIREMENT_DAYS));
        }

        $query->where(function ($statusQuery) use ($statuses): void {
            foreach ($statuses as $status) {
                $statusQuery->orWhere(fn ($q) => $this->applyStatus($q, $status));
            }
        });

        $questions = $query
            ->orderByDesc('time')
            ->orderByDesc('id')
            ->cursorPaginate($limit, ['*'], 'cursor');

        return response()->json([
            'data' => collect($questions->items())->map(fn (Block $block) => FlintQuestionPresenter::present($block))->all(),
            'next_cursor' => $questions->nextCursor()?->encode(),
            'has_more' => $questions->hasMorePages(),
            'meta' => [
                'effective_timezone' => $timezones->timezoneFor($request->user()),
                'account_id' => (string) $request->user()->id,
            ],
        ]);
    }

    public function storeAction(Request $request, string $block, FlintQuestionActionService $actions): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['answer', 'correct', 'skip'])],
            'answer' => ['nullable', 'required_if:action,answer,correct', 'string', 'max:1000', 'prohibited_if:action,skip'],
            'context' => ['nullable', 'string', 'max:1000', 'prohibited_if:action,skip'],
        ]);
        $mutationId = $request->header('Idempotency-Key');
        if (! is_string($mutationId) || ! Str::isUuid($mutationId)) {
            return response()->json(['message' => 'A UUID Idempotency-Key header is required.'], 422);
        }

        $result = $actions->record($request->user(), $block, $validated, $request->header('If-Match'), $mutationId);
        $payload = isset($result['data']) ? ['data' => $result['data']] : ['message' => $result['message'] ?? 'Unable to update question.'];
        if (isset($result['etag']) && ! isset($result['data'])) {
            $payload['etag'] = $result['etag'];
        }

        $response = response()->json($payload, $result['status']);

        return isset($result['etag']) && $result['etag'] !== '' ? $response->header('ETag', $result['etag']) : $response;
    }

    /** Mirrors FlintQuestion::status()'s precedence: answered, then skipped, then retired, else open. */
    private function applyStatus(Builder $query, string $status): void
    {
        match ($status) {
            'answered' => $query->whereNotNull('metadata->answer'),
            'skipped' => $query->whereNull('metadata->answer')
                ->where(fn ($q) => $q->whereNotNull('metadata->skipped_at')->orWhere('metadata->question_status', 'skipped')),
            'retired' => $query->whereNull('metadata->answer')
                ->whereNotNull('metadata->retired_at')
                ->whereNull('metadata->skipped_at')
                ->where(fn ($q) => $q->whereNull('metadata->question_status')->orWhere('metadata->question_status', '!=', 'skipped')),
            default => $query->whereNull('metadata->answer')
                ->whereNull('metadata->retired_at')
                ->whereNull('metadata->skipped_at'),
        };
    }

    /** A relative window ("48h", "7d") or an ISO timestamp, resolved against `$now`. */
    private function parseSince(string $input, Carbon $now): ?Carbon
    {
        if (preg_match('/^(\d+)([hd])$/', trim($input), $matches) === 1) {
            $amount = (int) $matches[1];

            return $matches[2] === 'h' ? $now->copy()->subHours($amount) : $now->copy()->subDays($amount);
        }

        try {
            return Carbon::parse($input);
        } catch (Exception) {
            return null;
        }
    }
}
