<?php

namespace App\Livewire\Money;

use App\Livewire\Concerns\ToleratesRepeatedLazyLoad;
use App\Services\Money\PlanOverview as PlanOverviewService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Lazy;
use Livewire\Component;

#[Lazy]
class PlanOverview extends Component
{
    use ToleratesRepeatedLazyLoad;

    public function placeholder(): string
    {
        return '<div class="p-6"><span class="loading loading-spinner"></span></div>';
    }

    public function render(): View
    {
        return view('livewire.money.plan-overview', app(PlanOverviewService::class)->build((string) Auth::id()));
    }
}
