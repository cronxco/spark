@props(['block'])

@php
use App\Integrations\PluginRegistry;

$pluginClass = PluginRegistry::getPlugin($block->event->service);
$serviceName = $pluginClass ? $pluginClass::getDisplayName() : ucfirst($block->event->service);
$icon = $pluginClass ? $pluginClass::getIcon() : 'fas.hexagon-nodes';
$accentColor = $pluginClass ? $pluginClass::getAccentColor() : 'warning';

$iconColorClass = match ($accentColor) {
    'primary' => 'text-primary',
    'secondary' => 'text-secondary',
    'accent' => 'text-accent',
    'success' => 'text-success',
    'warning' => 'text-warning',
    'error' => 'text-error',
    'info' => 'text-info',
    default => 'text-warning',
};
@endphp

<div class="card bg-base-200 shadow hover:shadow-lg transition-all">
    <div class="card-body p-4 gap-3">
        {{-- Header --}}
        <div class="flex items-start justify-between gap-2">
            <div class="badge badge-neutral badge-outline badge-sm gap-1">
                <x-icon name="fas.pen-nib" class="w-3 h-3" />
                Editorial Note
            </div>
            <div class="text-xs text-base-content/50">
                {{ $block->time->diffForHumans() }}
            </div>
        </div>

        {{-- Title --}}
        <h3 class="text-base font-semibold text-base-content line-clamp-2">
            {{ $block->title }}
        </h3>

        {{-- Content --}}
        @if ($block->getContent())
            <div class="prose prose-sm max-w-none text-base-content/80">
                {!! $block->getContentAsHtml() !!}
            </div>
        @endif

        {{-- Footer --}}
        <div class="flex items-center justify-between gap-2 pt-2 border-t border-base-300">
            <div class="flex items-center gap-1.5">
                <x-icon :name="$icon" class="w-4 h-4 {{ $iconColorClass }}" />
                <span class="text-xs font-medium text-base-content/70">{{ $serviceName }}</span>
            </div>

            <div class="flex items-center gap-2">
                @livewire('block-feedback', ['block' => $block], key('feedback-' . $block->id))

                <a
                    href="{{ route('blocks.show', $block) }}"
                    wire:navigate
                    class="btn btn-ghost btn-xs gap-1"
                >
                    <x-icon name="o-eye" class="w-3 h-3" />
                    View
                </a>
            </div>
        </div>
    </div>
</div>
