<?php

namespace App\Livewire\Money;

use App\Livewire\Concerns\ToleratesRepeatedLazyLoad;
use App\Services\Money\BufferDeposit;
use App\Services\Money\SpendingScope;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Lazy;
use Livewire\Component;
use RuntimeException;

#[Lazy]
class Spending extends Component
{
    use ToleratesRepeatedLazyLoad;

    public int $monthOffset = 0;

    public string $category = '';

    public string $account = '';

    /** @var list<string> */
    public array $selected = [];

    /** @var array<string, mixed>|null */
    public ?array $review = null;

    public ?string $bufferError = null;

    public ?string $bufferNotice = null;

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

    public function reviewBuffer(): void
    {
        $this->bufferError = $this->bufferNotice = null;
        try {
            $this->review = app(BufferDeposit::class)->prepare((string) Auth::id(), $this->selected);
        } catch (RuntimeException $e) {
            $this->review = null;
            $this->bufferError = $e->getMessage();
        }
    }

    public function cancelBuffer(): void
    {
        $this->review = null;
    }

    public function confirmBuffer(): void
    {
        if ($this->review === null) {
            return;
        }

        try {
            $result = app(BufferDeposit::class)->request((string) Auth::id(), $this->selected, (int) $this->review['total']);
            $this->bufferNotice = 'Asked Monzo to move £' . number_format($result['total'] / 100, 2) . ' into ' . $this->review['pot']['name'] . '. The transactions will show as buffered once it appears in Monzo.';
            $this->selected = [];
        } catch (RuntimeException $e) {
            $this->bufferError = $e->getMessage();
        }
        $this->review = null;
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
            'bufferEnabled' => (bool) config('money.buffer.enabled'),
        ]);
    }
}
