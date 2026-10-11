<?php

namespace App\Livewire\Money;

use App\Services\Money\BudgetService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Livewire\Attributes\Lazy;
use Livewire\Component;

#[Lazy]
class Budgets extends Component
{
    public string $name = '';

    public string $amount = '';

    public string $categories = '';

    public function placeholder(): string
    {
        return '<div class="p-6"><span class="loading loading-spinner"></span></div>';
    }

    public function create(): void
    {
        $this->resetErrorBag();
        $pounds = (float) str_replace(['£', ','], '', $this->amount);

        try {
            app(BudgetService::class)->create((string) Auth::id(), $this->name, [
                'amount' => (int) round($pounds * 100),
                'categories' => explode(',', $this->categories),
            ]);
        } catch (InvalidArgumentException $e) {
            $this->addError('name', $e->getMessage());

            return;
        }

        $this->reset(['name', 'amount', 'categories']);
    }

    public function archive(string $budgetId): void
    {
        app(BudgetService::class)->archive((string) Auth::id(), $budgetId);
    }

    public function render(): View
    {
        return view('livewire.money.budgets', [
            'budgets' => app(BudgetService::class)->progress((string) Auth::id()),
            'knownCategories' => config('money.categories'),
        ]);
    }
}
