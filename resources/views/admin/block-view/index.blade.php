<x-layouts.app :title="__('Block View')">
    <div>
        <x-header title="Block View" subtitle="Latest block of each type displayed using appropriate block cards" separator>
            <x-slot:actions>
                <a href="{{ route('admin.blocks.index') }}" class="btn btn-ghost btn-sm">
                    <x-icon name="o-table-cells" class="w-4 h-4" />
                    View Admin Table
                </a>
            </x-slot:actions>
        </x-header>

        <div class="mb-4">
            <div class="stats shadow">
                <div class="stat">
                    <div class="stat-title">Total Block Types</div>
                    <div class="stat-value text-primary">{{ $blocks->count() }}</div>
                    <div class="stat-desc">Showing latest of each type</div>
                </div>
            </div>
        </div>

        @if ($blocks->isEmpty())
            <div class="alert alert-info">
                <x-icon name="fas.circle-info" class="w-5 h-5" />
                <span>No blocks found in the database.</span>
            </div>
        @else
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach ($blocks as $block)
                    <x-block-card :block="$block" />
                @endforeach
            </div>
        @endif
    </div>
</x-layouts.app>
