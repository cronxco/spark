<?php

namespace App\Livewire\Money;

use App\Services\Money\NetWorth;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Lazy;
use Livewire\Component;

#[Lazy]
class Worth extends Component
{
    public string $window = '1month';

    public function placeholder(): string
    {
        return '<div class="p-6"><span class="loading loading-spinner"></span></div>';
    }

    public function render(): View
    {
        $window = array_key_exists($this->window, NetWorth::WINDOWS) ? $this->window : '1month';

        return view('livewire.money.worth', [
            'worth' => app(NetWorth::class)->build(Auth::user(), $window),
            'windows' => ['1week' => '1 week', '1month' => '1 month', '3months' => '3 months', '6months' => '6 months', '1year' => '1 year'],
        ]);
    }
}
