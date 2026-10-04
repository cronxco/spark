<?php

namespace App\Http\Controllers;

use RuntimeException;
use App\Integrations\GoCardless\GoCardlessBankPlugin;
use App\Models\IntegrationGroup;
use App\Services\GoCardlessAccounts;
use Illuminate\Http\Request;

class GoCardlessRenewalController extends Controller
{
    public function show(Request $request, IntegrationGroup $group)
    {
        abort_unless($group->user_id === $request->user()->id && $group->service === 'gocardless', 404);
        $pending = $group->auth_metadata['gocardless_pending'] ?? [];
        abort_unless(! empty($pending['requires_mapping']), 404);
        $oldAccounts = $group->integrations()->get()->pluck('configuration.account_id')->filter()->unique()
            ->mapWithKeys(fn ($id) => [$id => app(GoCardlessAccounts::class)->find($group->user_id, $id)?->title ?? 'Previously linked account']);

        return view('integrations.gocardless-renewal', compact('group', 'pending', 'oldAccounts'));
    }

    public function store(Request $request, IntegrationGroup $group, GoCardlessBankPlugin $plugin)
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
}
