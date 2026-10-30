@props(['block'])

@php
$checked = (bool) ($block->metadata['checked'] ?? false);
@endphp

<x-block-card.shell :block="$block">
    <div class="flex items-start gap-3">
        <span @class([
            'mt-0.5 w-5 h-5 shrink-0 rounded flex items-center justify-center',
            'bg-domain-knowledge text-base-100' => $checked,
            'border-2 border-base-content/30' => ! $checked,
        ]) aria-hidden="true">
            @if ($checked)
                <x-icon name="fas.check" class="w-3 h-3" />
            @endif
        </span>
        <span class="sr-only">{{ $checked ? 'Done:' : 'To do:' }}</span>
        <div @class(['text-base [&_p]:inline', 'line-through text-base-content/50' => $checked])>
            {!! Str::of($block->title)->inlineMarkdown()->trim() !!}
        </div>
    </div>
</x-block-card.shell>
