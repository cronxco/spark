@php
    $money = fn (int $pence): string => '£'.number_format($pence / 100, $pence % 100 === 0 ? 0 : 2);
@endphp
<div class="card bg-base-200 shadow">
    <div class="card-body gap-4">
        <h2 class="card-title">Budgets</h2>

        @forelse ($budgets as $budget)
            <div class="flex flex-col gap-1" wire:key="budget-{{ $budget['id'] }}">
                <div class="flex items-baseline justify-between gap-3">
                    <span class="font-medium">{{ $budget['name'] }}</span>
                    <span class="text-sm">
                        {{ $money($budget['spent']) }} of {{ $money($budget['limit']) }}
                        <span class="text-base-content/60">
                            {{ $budget['remaining'] >= 0 ? $money($budget['remaining']).' left' : $money(-$budget['remaining']).' over' }}
                        </span>
                    </span>
                </div>
                <progress
                    class="progress {{ ['ok' => 'progress-success', 'at_risk' => 'progress-warning', 'over' => 'progress-error'][$budget['status']] }} w-full"
                    value="{{ min(100, round($budget['ratio'] * 100)) }}" max="100"></progress>
                <div class="flex items-center justify-between text-xs text-base-content/60">
                    <span>{{ implode(', ', array_map(fn ($c) => str_replace('_', ' ', $c), $budget['categories'])) ?: 'No categories yet' }}</span>
                    <button type="button" class="link" wire:click="archive('{{ $budget['id'] }}')" wire:confirm="Archive this budget?">Archive</button>
                </div>
            </div>
        @empty
            <p class="text-sm text-base-content/70">No budgets yet. Add one below.</p>
        @endforelse

        <form wire:submit="create" class="grid grid-cols-1 sm:grid-cols-4 gap-2 items-end">
            <label class="form-control">
                <span class="label-text">Name</span>
                <input type="text" wire:model="name" class="input input-bordered input-sm" placeholder="Eating out" />
            </label>
            <label class="form-control">
                <span class="label-text">Monthly limit (£)</span>
                <input type="text" inputmode="decimal" wire:model="amount" class="input input-bordered input-sm" placeholder="250" />
            </label>
            <label class="form-control">
                <span class="label-text">Categories</span>
                <input type="text" wire:model="categories" list="budget-categories" class="input input-bordered input-sm" placeholder="eating_out, groceries" />
                <datalist id="budget-categories">
                    @foreach ($knownCategories as $category)
                        <option value="{{ $category }}"></option>
                    @endforeach
                </datalist>
            </label>
            <button type="submit" class="btn btn-primary btn-sm">Add budget</button>
        </form>
        @error('name') <p class="text-error text-sm">{{ $message }}</p> @enderror
    </div>
</div>
