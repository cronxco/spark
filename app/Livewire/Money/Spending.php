<?php

namespace App\Livewire\Money;

use App\Services\Money\SpendingScope;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Lazy;
use Livewire\Component;

#[Lazy]
class Spending extends Component
{
    public int $monthOffset = 0;

    public string $category = '';

    public string $account = '';

    public function placeholder(): string
    {
        return '<div class="p-6"><span class="loading loading-spinner"></span></div>';
    }

    public function previousMonth(): void
    {
        $this->monthOffset--;
    }

    public function nextMonth(): void
    {
        $this->monthOffset = min(0, $this->monthOffset + 1);
    }

    public function render(): View
    {
        $month = CarbonImmutable::now()->startOfMonth()->addMonths($this->monthOffset);
        $all = app(SpendingScope::class)->entries((string) Auth::id(), $month, $month->endOfMonth());
        $entries = $all
            ->when($this->category !== '', fn ($rows) => $rows->where('category', $this->category))
            ->when($this->account !== '', fn ($rows) => $rows->where('account_id', $this->account))
            ->values();

        return view('livewire.money.spending', [
            'month' => $month,
            'entries' => $entries->take(200),
            'total' => (int) $entries->sum('amount'),
            'count' => $entries->count(),
            'categories' => $all->pluck('category')->unique()->sort()->values(),
            'accounts' => $all->pluck('account', 'account_id')->unique()->sort(),
        ]);
    }
}
