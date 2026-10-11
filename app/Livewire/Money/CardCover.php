<?php

namespace App\Livewire\Money;

use App\Services\Money\CardCover as CardCoverService;
use App\Services\Money\PlanOverview as PlanOverviewService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Livewire\Attributes\Lazy;
use Livewire\Component;

#[Lazy]
class CardCover extends Component
{
    public function placeholder(): string
    {
        return '<div class="p-6"><span class="loading loading-spinner"></span></div>';
    }

    public function useAsBuffer(?string $potId): void
    {
        try {
            app(CardCoverService::class)->setBufferPot((string) Auth::id(), $potId === '' ? null : $potId);
        } catch (InvalidArgumentException $e) {
            $this->addError('buffer', $e->getMessage());
        }
    }

    public function render(): View
    {
        $userId = (string) Auth::id();

        return view('livewire.money.card-cover', [
            'cover' => app(CardCoverService::class)->build($userId),
            'pots' => collect(app(PlanOverviewService::class)->build($userId)['pots']),
        ]);
    }
}
