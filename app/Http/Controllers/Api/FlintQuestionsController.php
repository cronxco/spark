<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Block;
use App\Services\Flint\FlintQuestionAnswerer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FlintQuestionsController extends Controller
{
    public function answer(Request $request, Block $block): JsonResponse
    {
        $block->loadMissing('event.integration');

        if ($block->event?->integration?->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Forbidden.'], 403);
        }

        if ($block->block_type !== 'flint_user_question') {
            return response()->json(['error' => 'This block is not a user question.'], 422);
        }

        $validated = $request->validate([
            'answer' => ['required', 'string', 'max:1000'],
            'answer_note' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json(
            app(FlintQuestionAnswerer::class)->record(
                $block,
                $validated['answer'],
                $validated['answer_note'] ?? null,
            )
        );
    }
}
