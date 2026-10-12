<?php

namespace App\Livewire\Concerns;

/**
 * Safe sort and page-size handling for admin list components.
 *
 * Sort state and page size are public Livewire properties, so the browser can
 * send anything; only columns the component declares sortable are honoured.
 */
trait HasAdminListControls
{
    public function updatedPerPage(): void
    {
        $this->perPage = $this->resolvedPerPage();
        $this->resetPage();
    }

    /**
     * @return array<int, int>
     */
    protected function allowedPerPage(): array
    {
        return [10, 25, 50, 100];
    }

    /**
     * @return array{0: string, 1: string} column and direction
     */
    protected function resolvedSort(string $defaultColumn): array
    {
        $sortable = collect($this->headers())->where('sortable', true)->pluck('key')->all();
        $column = $this->sortBy['column'] ?? null;
        $direction = ($this->sortBy['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return [in_array($column, $sortable, true) ? $column : $defaultColumn, $direction];
    }

    protected function resolvedPerPage(): int
    {
        return in_array($this->perPage, $this->allowedPerPage(), true) ? $this->perPage : 25;
    }
}
