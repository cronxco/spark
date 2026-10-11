<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Api\V1\Mobile\Concerns\HandlesIdempotency;
use App\Http\Controllers\Controller;
use App\Services\Money\BudgetService;
use App\Services\Money\BufferDeposit;
use App\Services\Money\CardCover;
use App\Services\Money\PlanSnapshot;
use App\Services\Money\SpendingScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

/**
 * The Money Plan surface for the mobile app: the same figures as the web Plan tab, from the same
 * services. All amounts are integer pence.
 */
class MoneyPlanController extends Controller
{
    use HandlesIdempotency;

    public function __construct(
        private PlanSnapshot $snapshot,
        private CardCover $cover,
        private BudgetService $budgets,
        private SpendingScope $scope,
        private BufferDeposit $buffer,
    ) {}

    /**
     * GET /api/v1/mobile/money/plan
     */
    public function plan(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->snapshot->build((string) $request->user()->id)]);
    }

    /**
     * GET /api/v1/mobile/money/spending?month=2026-10&category=&account=&person=&budget=
     */
    public function spending(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'category' => ['nullable', 'string', 'max:80'],
            'account' => ['nullable', 'string', 'max:64'],
            'person' => ['nullable', 'string', 'max:80'],
            'budget' => ['nullable', 'string', 'max:64'],
        ]);

        $month = CarbonImmutable::createFromFormat('Y-m-d', ($validated['month'] ?? now()->format('Y-m')) . '-01')->startOfMonth();
        $entries = $this->scope->entries((string) $request->user()->id, $month, $month->endOfMonth(), array_filter([
            'category' => $validated['category'] ?? null,
            'account' => $validated['account'] ?? null,
            'person' => $validated['person'] ?? null,
            'budget' => $validated['budget'] ?? null,
        ]));

        return response()->json(['data' => [
            'month' => $month->format('Y-m'),
            'total' => (int) $entries->sum('amount'),
            'transactions' => $entries->map(fn (array $entry): array => ['time' => $entry['time']->toIso8601String()] + $entry)->values(),
        ]]);
    }

    public function budgetsIndex(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->budgets->progress((string) $request->user()->id)]);
    }

    public function budgetsStore(Request $request): JsonResponse
    {
        $validated = $this->validateBudget($request, true);

        try {
            $budget = $this->budgets->create((string) $request->user()->id, $validated['name'], $validated);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->budgetRow($request, (string) $budget->id)], 201);
    }

    public function budgetsUpdate(Request $request, string $id): JsonResponse
    {
        $validated = $this->validateBudget($request, false);
        $userId = (string) $request->user()->id;

        try {
            $this->budgets->update($userId, $id, $validated, $validated['name'] ?? null);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->budgetRow($request, $id)]);
    }

    public function budgetsDestroy(Request $request, string $id): JsonResponse
    {
        $this->budgets->archive((string) $request->user()->id, $id);

        return response()->json(null, 204);
    }

    /**
     * POST /api/v1/mobile/money/buffer/preview  {event_ids: []}
     */
    public function bufferPreview(Request $request): JsonResponse
    {
        $validated = $request->validate(['event_ids' => ['required', 'array', 'min:1', 'max:100'], 'event_ids.*' => ['string']]);

        try {
            $plan = $this->buffer->prepare((string) $request->user()->id, $validated['event_ids']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->planRow($plan)]);
    }

    /**
     * POST /api/v1/mobile/money/buffer  {event_ids: [], confirmed_total: 6500}  + Idempotency-Key
     */
    public function bufferStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'event_ids' => ['required', 'array', 'min:1', 'max:100'],
            'event_ids.*' => ['string'],
            'confirmed_total' => ['required', 'integer', 'min:1'],
        ]);

        return $this->idempotent($request, 'money.buffer', function () use ($request, $validated): JsonResponse {
            try {
                $result = $this->buffer->request((string) $request->user()->id, $validated['event_ids'], (int) $validated['confirmed_total']);
            } catch (RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return response()->json(['data' => $result], 202);
        });
    }

    /**
     * PATCH /api/v1/mobile/money/pots/{id}  {card_buffer: true|false}
     */
    public function potUpdate(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['card_buffer' => ['required', 'boolean']]);
        $userId = (string) $request->user()->id;

        try {
            if ($validated['card_buffer']) {
                $this->cover->setBufferPot($userId, $id);
            } elseif ((string) $this->cover->bufferPot($userId)?->id === $id) {
                $this->cover->setBufferPot($userId, null);
            }
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json(['data' => ['card_buffer_pot_id' => $this->cover->bufferPot($userId)?->id]]);
    }

    /**
     * PATCH /api/v1/mobile/money/cards/{id}  {statement_day: 7|null, payment_day: 19|null}
     */
    public function cardUpdate(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'statement_day' => ['present', 'nullable', 'integer', 'between:1,31'],
            'payment_day' => ['nullable', 'integer', 'between:1,31'],
        ]);

        try {
            $this->cover->setBilling((string) $request->user()->id, $id, $validated['statement_day'], $validated['payment_day'] ?? null);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json(['data' => ['statement_day' => $validated['statement_day'], 'payment_day' => $validated['payment_day'] ?? null]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateBudget(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$required, 'string', 'max:80'],
            'amount' => [$required, 'integer', 'min:1', 'max:100000000'],
            'categories' => ['sometimes', 'array', 'max:30'],
            'categories.*' => ['string', 'max:80'],
            'merchants_include' => ['sometimes', 'array', 'max:50'],
            'merchants_include.*' => ['string', 'max:120'],
            'merchants_exclude' => ['sometimes', 'array', 'max:50'],
            'merchants_exclude.*' => ['string', 'max:120'],
            'accounts' => ['sometimes', 'array', 'max:30'],
            'accounts.*' => ['string', 'max:64'],
            'people' => ['sometimes', 'array', 'max:10'],
            'people.*' => ['string', 'max:80'],
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function budgetRow(Request $request, string $id): ?array
    {
        return collect($this->budgets->progress((string) $request->user()->id))->firstWhere('id', $id);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function planRow(array $plan): array
    {
        $plan['transactions'] = array_map(fn (array $entry): array => ['time' => $entry['time']->toIso8601String()] + $entry, $plan['transactions']);

        return $plan;
    }
}
