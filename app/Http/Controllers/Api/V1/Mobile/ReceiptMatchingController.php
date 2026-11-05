<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\Receipt\ReceiptMatchingActions;
use App\Services\Receipt\ReceiptMatchState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ReceiptMatchingController extends Controller
{
    public function unmatched(Request $request): JsonResponse
    {
        $receipts = Event::forUser($request->user()->id)
            ->where('service', 'receipt')->where('action', 'had_receipt_from')
            ->whereNotIn('id', ReceiptMatchState::links()->select('from_id'))
            ->with('target')->orderByDesc('time')->paginate(25);

        return response()->json([
            'data' => $receipts->getCollection()->map(fn (Event $receipt) => $this->summary($receipt))->all(),
            'meta' => ['current_page' => $receipts->currentPage(), 'last_page' => $receipts->lastPage(), 'total' => $receipts->total()],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->summary($this->receipt($request, $id))]);
    }

    public function search(Request $request, ReceiptMatchingActions $actions, string $id): JsonResponse
    {
        $query = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $matches = $actions->search($this->receipt($request, $id), $query['q'] ?? null);

        return response()->json(['data' => $matches->map(fn (Event $event) => $this->event($event))->all()]);
    }

    public function retry(Request $request, ReceiptMatchingActions $actions, string $id): JsonResponse
    {
        $receipt = $this->receipt($request, $id);
        try {
            $actions->retry($receipt);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $this->summary($receipt->fresh('target'))], 202);
    }

    public function link(Request $request, ReceiptMatchingActions $actions, string $id): JsonResponse
    {
        $input = $request->validate(['transaction_id' => ['required', 'uuid']]);
        $receipt = $this->receipt($request, $id);
        $transaction = Event::forUser($request->user()->id)->findOrFail($input['transaction_id']);
        try {
            $actions->link($receipt, $transaction);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $this->summary($receipt->fresh('target'))]);
    }

    public function noMatch(Request $request, ReceiptMatchingActions $actions, string $id): JsonResponse
    {
        $receipt = $this->receipt($request, $id);
        try {
            $actions->markNoMatch($receipt);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $this->summary($receipt->fresh('target'))]);
    }

    public function unlink(Request $request, ReceiptMatchingActions $actions, string $id): JsonResponse
    {
        $receipt = $this->receipt($request, $id);
        $actions->unlink($receipt);

        return response()->json(['data' => $this->summary($receipt->fresh('target'))]);
    }

    private function receipt(Request $request, string $id): Event
    {
        return Event::forUser($request->user()->id)
            ->where('service', 'receipt')->where('action', 'had_receipt_from')
            ->with('target')->findOrFail($id);
    }

    private function summary(Event $receipt): array
    {
        $link = ReceiptMatchState::link($receipt);
        $transaction = $link ? Event::forUser($receipt->integration?->user_id)->with('target')->find($link->to_id) : null;
        $state = ReceiptMatchState::state($receipt);
        $candidateIds = collect($state['candidates'] ?? [])->pluck('transaction_id')->filter();
        $owned = Event::forUser($receipt->integration?->user_id)->whereIn('id', $candidateIds)->with('target')->get()->keyBy('id');

        return [
            ...$this->event($receipt),
            'status' => ReceiptMatchState::status($receipt),
            'reason' => $state['reason'] ?? null,
            'attempted_at' => $state['attempted_at'] ?? null,
            'matched' => $transaction ? $this->event($transaction) : null,
            'candidates' => collect($state['candidates'] ?? [])->filter(fn ($candidate) => $owned->has($candidate['transaction_id'] ?? null))
                ->map(fn ($candidate) => [...$this->event($owned[$candidate['transaction_id']]), 'confidence' => $candidate['confidence'] ?? null])->values()->all(),
        ];
    }

    private function event(Event $event): array
    {
        return [
            'id' => (string) $event->id,
            'title' => $event->target?->title,
            'amount' => $event->formatted_value,
            'unit' => $event->value_unit,
            'time' => $event->time?->toIso8601String(),
            'service' => $event->service,
        ];
    }
}
