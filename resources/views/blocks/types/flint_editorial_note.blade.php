@props(['block'])

<x-block-card.shell :block="$block" :title="$block->title">
    @if ($block->getContent())
        <div class="prose prose-sm max-w-none text-base-content/80 line-clamp-5">
            {!! $block->getContentAsHtml() !!}
        </div>
    @endif

    <x-slot:context>
        <div class="relative z-10 flex items-center gap-2">
            @livewire('block-feedback', ['block' => $block], key('feedback-' . $block->id))
        </div>
    </x-slot:context>
</x-block-card.shell>
