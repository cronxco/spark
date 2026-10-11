<?php

namespace App\Http\Controllers;

use App\Integrations\Financial\FinancialPlugin;
use App\Integrations\GoCardless\GoCardlessBankPlugin;
use App\Models\IntegrationGroup;
use App\Services\GoCardlessAccounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class GoCardlessRenewalController extends Controller
{
    public function store(Request $request, IntegrationGroup $group, GoCardlessBankPlugin $plugin): RedirectResponse
    {
        abort_unless($group->user_id === $request->user()->id && $group->service === 'gocardless', 404);
        $validated = $request->validate(['reference' => ['required', 'string'],
            'mapping' => ['required', 'array'], 'mapping.*' => ['required', 'string']]);
        try {
            $plugin->completeRenewal($group, $validated['reference'], $validated['mapping']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['mapping' => $e->getMessage()])->withInput();
        }
        $meta = $group->fresh()->auth_metadata ?? [];
        $mobile = $meta['mobile_reauth_origin'] ?? false;
        $attemptId = $meta['mobile_reauth_attempt_id'] ?? null;
        unset($meta['mobile_reauth_origin'], $meta['mobile_reauth_started_at'], $meta['mobile_reauth_attempt_id']);
        $group->update(['auth_metadata' => $meta]);
        if ($mobile) {
            return redirect()->away('spark://integrations/reauth-complete?' . http_build_query(['status' => 'success', 'attempt_id' => $attemptId]));
        }

        return redirect()->route('integrations.index')->with('success', 'Bank connection renewed. Previously paused accounts remain paused.');
    }

    public function show(Request $request, IntegrationGroup $group, GoCardlessBankPlugin $plugin): View
    {
        abort_unless($group->user_id === $request->user()->id && $group->service === 'gocardless', 404);
        $pending = $group->auth_metadata['gocardless_pending'] ?? [];
        abort_unless(! empty($pending['requires_mapping']), 404);
        $accounts = app(GoCardlessAccounts::class);
        $oldAccounts = $group->integrations()->get()->pluck('configuration.account_id')->filter()->unique()
            ->mapWithKeys(function ($id) use ($group, $accounts, $plugin) {
                $object = $accounts->find($group->user_id, $id);
                $balance = $object ? (new FinancialPlugin)->getLatestBalance($object) : null;
                $details = $plugin->recognisableAccountDetails($object?->metadata['raw'] ?? []);

                return [$id => [
                    'title' => $object?->title ?? 'Previously linked account',
                    'summary' => $this->summary($details, $balance
                        ? $this->money(abs((float) $balance->formatted_value), $balance->value_unit) .
                            ' as of ' . $balance->time?->format('j M Y') : null, 'Last known balance'),
                ]];
            });
        $balances = $plugin->pendingAccountBalances(array_keys($pending['accounts'] ?? []));
        $newAccounts = collect($pending['accounts'] ?? [])->map(function ($details, $id) use ($plugin, $balances) {
            $balance = $balances[$id] ?? null;
            $parts = array_filter([
                GoCardlessBankPlugin::generateAccountName($details), $details['currency'] ?? null,
                $this->summary($plugin->recognisableAccountDetails($details), $balance
                    ? $this->money($balance['amount'], $balance['currency'] ?? ($details['currency'] ?? null)) : 'unavailable', 'Balance'),
            ]);

            return implode(' · ', $parts);
        });

        return view('integrations.gocardless-renewal', [
            'group' => $group, 'pending' => $pending, 'oldAccounts' => $oldAccounts, 'newAccounts' => $newAccounts,
        ]);
    }

    /** @param  array{type: ?string, sort_code: ?string, mask: ?string}  $details */
    private function summary(array $details, ?string $balance, string $balanceLabel): string
    {
        return implode(' · ', array_filter([
            $details['type'], $details['sort_code'] ? 'Sort code ' . $details['sort_code'] : null,
            $details['mask'], $balance ? $balanceLabel . ' ' . $balance : null,
        ]));
    }

    private function money(float $amount, ?string $currency): string
    {
        $symbol = ['GBP' => '£', 'EUR' => '€', 'USD' => '$'][$currency ?? ''] ?? (($currency ? $currency . ' ' : ''));

        return $symbol . number_format($amount, 2);
    }
}
