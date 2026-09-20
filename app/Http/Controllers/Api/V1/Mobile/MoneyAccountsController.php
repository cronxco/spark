<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Api\V1\Mobile\Concerns\HandlesIdempotency;
use App\Http\Controllers\Controller;
use App\Http\Resources\Compact\BalanceEntryResource;
use App\Http\Resources\Compact\MoneyAccountResource;
use App\Integrations\Financial\FinancialPlugin;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Services\Api\ResourceVersion;
use App\Support\CollectionCursorPage;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MoneyAccountsController extends Controller
{
    use HandlesIdempotency;

    public function __construct(protected FinancialPlugin $financial, protected ResourceVersion $versions) {}

    /**
     * GET /api/v1/mobile/money/accounts?limit=50&cursor=...
     *
     * Returns all non-archived accounts with their latest balance.
     *
     * MR-14: paginated with the same cursor envelope as every other mobile
     * collection endpoint, even though the list is short today.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.CollectionCursorPage::MAX_LIMIT],
            'cursor' => ['nullable', 'string'],
        ]);
        $limit = (int) ($validated['limit'] ?? CollectionCursorPage::DEFAULT_LIMIT);

        $user = $request->user();
        $accounts = $this->financial->getFinancialAccounts($user);
        $latestBalances = $this->financial->getLatestBalancesForAccounts($accounts);

        [$page, $nextCursor, $hasMore] = CollectionCursorPage::paginate(
            $accounts,
            $validated['cursor'] ?? null,
            $limit,
        );

        $data = $page->map(function (EventObject $account) use ($latestBalances) {
            return (new MoneyAccountResource($account))
                ->withBalance($latestBalances->get($account->id));
        });

        $lastModified = $accounts->max('updated_at');

        $response = response()->json([
            'data' => $data,
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
        ]);

        if ($lastModified) {
            $response->header('Last-Modified', Carbon::parse($lastModified)->toRfc7231String());
        }

        return $response;
    }

    /**
     * GET /api/v1/mobile/money/accounts/{id}
     *
     * Returns a single account with its latest balance.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $account = $this->resolveAccount($id, $user->id);

        if (! $account) {
            return response()->json(['message' => 'Account not found.'], 404);
        }

        $latestBalance = $this->financial->getLatestBalance($account);

        $resource = (new MoneyAccountResource($account))->withBalance($latestBalance);

        return response()->json(['data' => $resource->toArray($request)])
            ->header('Last-Modified', $account->updated_at->toRfc7231String())
            ->header('ETag', $this->versions->etag($account));
    }

    /**
     * GET /api/v1/mobile/money/accounts/{id}/balances
     *
     * Returns cursor-paginated balance history for an account.
     */
    public function balances(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $account = $this->resolveAccount($id, $user->id);

        if (! $account) {
            return response()->json(['message' => 'Account not found.'], 404);
        }

        $paginator = Event::where('actor_id', $account->id)
            ->whereIn('service', ['manual_account', 'monzo', 'gocardless'])
            ->where('action', 'had_balance')
            ->orderByDesc('time')
            ->orderByDesc('id')
            ->cursorPaginate(25, ['*'], 'cursor', $request->query('cursor'));

        return response()->json([
            'data' => BalanceEntryResource::collection($paginator->items()),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    /**
     * POST /api/v1/mobile/money/accounts
     *
     * Creates a new manual account.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'account_type' => ['required', 'string', 'in:current_account,savings_account,mortgage,investment_account,credit_card,loan,pension,other'],
            'currency' => ['required', 'string', 'in:GBP,USD,EUR'],
            'provider' => ['nullable', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:255'],
            'sort_code' => ['nullable', 'string', 'max:8'],
            'interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'is_negative_balance' => ['sometimes', 'boolean'],
        ]);

        $validated['is_negative_balance'] = $this->forceNegativeBalance(
            $validated['account_type'],
            $validated['is_negative_balance'] ?? false,
        );

        $user = $request->user();
        $integration = $this->resolveManualAccountIntegration($user);
        $account = $this->financial->upsertAccountObject($integration, $validated);

        $resource = (new MoneyAccountResource($account))->withBalance(null);

        return response()->json(['data' => $resource->toArray($request)], 201);
    }

    /**
     * PATCH /api/v1/mobile/money/accounts/{id}
     *
     * Updates a manual account. Returns 422 for non-manual accounts, except
     * for `is_pinned` (MR-6), which is user preference rather than account
     * data and so applies to any account the user owns — the client picking
     * which account the Day tab shows shouldn't require the account to be a
     * manually-tracked one.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $account = $this->resolveAccount($id, $user->id);

        if (! $account) {
            return response()->json(['message' => 'Account not found.'], 404);
        }

        $manualOnlyFields = ['name', 'account_type', 'currency', 'provider', 'account_number', 'sort_code', 'interest_rate', 'start_date', 'is_negative_balance'];
        $requestsManualOnlyField = collect($manualOnlyFields)->contains(fn (string $field) => $request->has($field));

        if ($account->type !== 'manual_account' && $requestsManualOnlyField) {
            return response()->json(['message' => 'Only manual accounts can be edited. is_pinned can be set on any account.'], 422);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'account_type' => ['sometimes', 'string', 'in:current_account,savings_account,mortgage,investment_account,credit_card,loan,pension,other'],
            'currency' => ['sometimes', 'string', 'in:GBP,USD,EUR'],
            'provider' => ['sometimes', 'nullable', 'string', 'max:255'],
            'account_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sort_code' => ['sometimes', 'nullable', 'string', 'max:8'],
            'interest_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'is_negative_balance' => ['sometimes', 'boolean'],
            'is_pinned' => ['sometimes', 'boolean'],
        ]);

        // At most one pinned account per user.
        if (($validated['is_pinned'] ?? false) === true) {
            $this->unpinOtherAccounts($user->id, $account->id);
        }

        $meta = $account->metadata ?? [];

        // Preserve fields the app must never overwrite
        $preserved = array_intersect_key($meta, array_flip(['integration_id', 'account_id', 'pot_id', 'raw']));

        $accountType = $validated['account_type'] ?? $meta['account_type'] ?? null;
        if ($accountType) {
            $validated['is_negative_balance'] = $this->forceNegativeBalance(
                $accountType,
                $validated['is_negative_balance'] ?? $meta['is_negative_balance'] ?? false,
            );
        }

        $newMeta = array_merge($meta, $validated, $preserved);
        if (isset($validated['name'])) {
            $newMeta['name'] = $validated['name'];
        }

        $account->update([
            'title' => $newMeta['name'] ?? $account->title,
            'metadata' => $newMeta,
        ]);

        $latestBalance = $this->financial->getLatestBalance($account);
        $resource = (new MoneyAccountResource($account->fresh()))->withBalance($latestBalance);

        return response()->json(['data' => $resource->toArray($request)])
            ->header('ETag', $this->versions->etag($account->fresh()));
    }

    /**
     * DELETE /api/v1/mobile/money/accounts/{id}
     *
     * Archives a manual account. Returns 422 for non-manual accounts.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $account = $this->resolveAccount($id, $user->id);

        if (! $account) {
            return response()->json(['message' => 'Account not found.'], 404);
        }

        if ($account->type !== 'manual_account') {
            return response()->json(['message' => 'Only manual accounts can be archived.'], 422);
        }

        $integration = $this->resolveManualAccountIntegration($user);

        // Mirror ArchiveFinancialAccount: create a final zero-balance event
        $this->financial->createBalanceEvent($integration, $account, [
            'balance' => 0,
            'date' => now()->toDateString(),
            'notes' => 'Archived on '.now()->toFormattedDayDateString(),
        ]);

        $meta = $account->metadata ?? [];
        $meta['deleted'] = true;
        $meta['archived_at'] = now()->toIso8601String();
        $account->update(['metadata' => $meta]);

        return response()->json(['message' => 'Account archived.'])
            ->header('ETag', $this->versions->etag($account->fresh()));
    }

    /**
     * POST /api/v1/mobile/money/accounts/{id}/balances
     *
     * Adds a balance update to an account.
     */
    public function addBalance(Request $request, string $id): JsonResponse
    {
        // MR-17: a phone on a bad connection retries this write; an
        // Idempotency-Key means a retry replays the first response instead
        // of recording the same balance twice.
        return $this->idempotent($request, 'money.balances.store', function () use ($request, $id) {
            $user = $request->user();
            $account = $this->resolveAccount($id, $user->id);

            if (! $account) {
                return response()->json(['message' => 'Account not found.'], 404);
            }

            $validated = $request->validate([
                'balance' => ['required', 'numeric'],
                'date' => ['required', 'date_format:Y-m-d'],
                'notes' => ['nullable', 'string', 'max:1000'],
            ]);

            $integration = $this->resolveManualAccountIntegration($user);

            $event = $this->financial->createBalanceEvent($integration, $account, $validated);
            // Balance history is part of the account's mutation surface, so move
            // the account version forward for clients retrying with If-Match.
            $account->touch();

            return response()->json(
                ['data' => (new BalanceEntryResource($event))->toArray($request)],
                201,
            )->header('ETag', $this->versions->etag($account->fresh()));
        });
    }

    /**
     * Find an account by ID that belongs to the given user.
     */
    private function resolveAccount(string $id, string $userId): ?EventObject
    {
        return EventObject::where('id', $id)
            ->where('user_id', $userId)
            ->where('concept', 'account')
            ->first();
    }

    /**
     * Clear `is_pinned` on every other account this user owns, so pinning one
     * account always leaves at most one pinned.
     */
    private function unpinOtherAccounts(string $userId, string $exceptAccountId): void
    {
        EventObject::where('user_id', $userId)
            ->where('concept', 'account')
            ->where('id', '!=', $exceptAccountId)
            ->get()
            ->each(function (EventObject $other): void {
                if (! ($other->metadata['is_pinned'] ?? false)) {
                    return;
                }

                $meta = $other->metadata;
                $meta['is_pinned'] = false;
                $other->update(['metadata' => $meta]);
            });
    }

    /**
     * Get or create the shared manual_account Integration for this user.
     * Mirrors the pattern in CheckInsController::resolveIntegration().
     */
    private function resolveManualAccountIntegration(mixed $user): Integration
    {
        $group = IntegrationGroup::firstOrCreate(
            ['user_id' => $user->id, 'service' => 'manual_account'],
            [
                'account_id' => Str::uuid()->toString(),
                'access_token' => 'mobile',
            ],
        );

        return Integration::firstOrCreate(
            ['user_id' => $user->id, 'service' => 'manual_account'],
            [
                'integration_group_id' => $group->id,
                'name' => 'Manual Accounts',
                'account_id' => $group->account_id,
            ],
        );
    }

    /**
     * Force is_negative_balance to true for debt-type accounts.
     */
    private function forceNegativeBalance(string $accountType, bool $current): bool
    {
        return in_array($accountType, ['credit_card', 'loan', 'mortgage'], true) ? true : $current;
    }
}
