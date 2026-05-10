<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Block;
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

        $answeredAt = now()->toIso8601String();

        $block->metadata = array_merge($block->metadata ?? [], [
            'answer' => $validated['answer'],
            'answer_note' => $validated['answer_note'] ?? null,
            'answered_at' => $answeredAt,
        ]);

        $block->save();

        return response()->json([
            'block_id' => $block->id,
            'answer' => $validated['answer'],
            'answer_note' => $validated['answer_note'] ?? null,
            'answered_at' => $answeredAt,
        ]);
    }
}
