<?php

namespace App\Livewire\Money;

use App\Livewire\Concerns\ToleratesRepeatedLazyLoad;
use App\Services\Money\CardCover as CardCoverService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Livewire\Attributes\Lazy;
use Livewire\Component;

#[Lazy]
class CardCover extends Component
{
    use ToleratesRepeatedLazyLoad;

    public function placeholder(): string
    {
        return '<div class="p-6"><span class="loading loading-spinner"></span></div>';
    }

    public function saveBilling(string $cardId, string|int|null $statementDay, string|int|null $paymentDay): void
    {
        $day = fn (string|int|null $value): ?int => $value === null || $value === '' ? null : (int) $value;

        try {
            app(CardCoverService::class)->setBilling((string) Auth::id(), $cardId, $day($statementDay), $day($paymentDay));
            $this->resetErrorBag('billing');
        } catch (InvalidArgumentException $e) {
            $this->addError('billing', $e->getMessage());
        }
    }

    public function render(): View
    {
        $userId = (string) Auth::id();

        return view('livewire.money.card-cover', [
            'cover' => app(CardCoverService::class)->build($userId),
        ]);
    }
}
