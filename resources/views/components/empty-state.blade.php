@props(['icon' => 'fas.inbox', 'message' => 'No items yet', 'action' => null, 'actionLabel' => 'Add', 'actionEvent' => null])

<div class="text-center py-4">
    <x-icon :name="$icon" class="w-8 h-8 mx-auto mb-2 text-base-content/30" />
    <p class="text-sm text-base-content/60 mb-2">{{ $message }}</p>
    @if ($action || $actionEvent)
        <button
            @if ($actionEvent) wire:click="{{ $actionEvent }}" @endif
            @if ($action && !$actionEvent) onclick="{{ $action }}" @endif
            class="btn btn-xs btn-primary gap-1">
            <x-icon name="fas-plus" class="w-3 h-3" />
            {{ $actionLabel }}
        </button>
    @endif
</div>
