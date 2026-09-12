@props(['block'])

@php
    $minutes = $block->value !== null ? (int) $block->formatted_value : null;
@endphp

<div class="card bg-base-200 shadow hover:shadow-lg transition-all">
    <div class="card-body p-4 gap-3">
        <div class="flex items-start justify-between gap-2">
            <div class="badge badge-neutral badge-outline badge-sm gap-1">
                <x-icon name="fas.trash-can" class="w-3 h-3" />
                Worth Dropping
            </div>
            @if ($minutes)
                <div class="text-xs text-base-content/60 font-mono tabular-nums">{{ $minutes }} min</div>
            @endif
        </div>

        <h3 class="text-base font-semibold text-base-content">
            @if ($block->url)
                <a href="{{ $block->url }}" target="_blank" rel="noopener noreferrer" class="link link-hover">
                    {{ $block->title }}
                </a>
            @else
                {{ $block->title }}
            @endif
        </h3>

        @if ($block->getContent())
            <div class="prose prose-sm max-w-none text-base-content/80">
                {!! $block->getContentAsHtml() !!}
            </div>
        @endif

        <div class="flex items-center justify-between gap-2 pt-2 border-t border-base-300">
            <div class="flex items-center gap-1.5">
                <x-icon name="fas.hexagon-nodes" class="w-4 h-4 text-warning" />
                <span class="text-xs font-medium text-base-content/70">Flint</span>
            </div>
            <div class="flex items-center gap-2">
                @livewire('block-feedback', ['block' => $block], key('feedback-' . $block->id))
                @if ($block->url)
                    <a href="{{ $block->url }}" target="_blank" rel="noopener noreferrer" class="btn btn-ghost btn-xs gap-1">
                        <x-icon name="o-arrow-top-right-on-square" class="w-3 h-3" />
                        Open
                    </a>
                @endif
            </div>
        </div>
    </div>
</div>
