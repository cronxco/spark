@props(['block'])

@php
    $minutes = $block->value !== null ? (int) $block->formatted_value : null;
@endphp

{{-- A reading pick is there to be read, so the card opens the article. --}}
<x-block-card.shell :block="$block" :title="$block->title" :href="$block->url ?: null">
    @if ($block->getContent())
        <div class="prose prose-sm max-w-none text-base-content/80 line-clamp-5">
            {!! $block->getContentAsHtml() !!}
        </div>
    @endif

    <x-slot:context>
        <div class="relative z-10 flex items-center justify-between gap-2">
            <span class="tabular-nums">{{ $minutes ? $minutes . ' min read' : '' }}</span>
            @livewire('block-feedback', ['block' => $block], key('feedback-' . $block->id))
        </div>
    </x-slot:context>
</x-block-card.shell>
