<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Block;
use App\Services\EffectiveTimezoneResolver;
use App\Services\Flint\FlintQuestionActionService;
use App\Support\FlintQuestion;
use App\Support\FlintQuestionPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class FlintQuestionsController extends Controller
{
    public function index(Request $request, EffectiveTimezoneResolver $timezones): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['open'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'cursor' => ['nullable', 'string'],
        ]);
        $limit = (int) ($validated['limit'] ?? 20);
        $cutoff = $timezones->now($request->user())->subDays(FlintQuestion::RETIREMENT_DAYS);

        $questions = Block::query()
            ->select(['blocks.*', 'xmin'])
            ->where('block_type', FlintQuestion::BLOCK_TYPE)
            ->where('time', '>=', $cutoff)
            ->whereNull('metadata->answer')
            ->whereNull('metadata->retired_at')
            ->whereNull('metadata->skipped_at')
            ->whereHas('event.integration', fn ($query) => $query->where('user_id', $request->user()->id))
            ->with('event')
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
}
