<?php

namespace App\Livewire\Money;

use App\Services\Money\AccountCleanup;
use App\Services\Money\CardCover as CardCoverService;
use App\Services\Money\PlanOverview as PlanOverviewService;
use App\Services\Money\SafeToSpend;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * The Money "Now" view: what is safe to spend, whether the cards are covered, the next bills and a
 * few headline figures. Everything is worked out at query time by the shared money services.
 */
#[Lazy]
class Now extends Component
{
    public function placeholder(): string
    {
        return '<div class="p-6"><span class="loading loading-spinner"></span></div>';
    }

    public function render(): View
    {
        $userId = (string) Auth::id();
        $cover = app(CardCoverService::class)->build($userId);
        $plan = app(PlanOverviewService::class)->build($userId);

        return view('livewire.money.now', [
            'safe' => app(SafeToSpend::class)->build($userId, $cover['owed']),
            'cover' => $cover,
            'strip' => $plan['strip'],
            'dataIssues' => Auth::user()?->is_admin ? count(app(AccountCleanup::class)->plan($userId)['steps']) : 0,
        ]);
    }
}
