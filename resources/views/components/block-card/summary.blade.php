{{-- Generated text in a card (DESIGN_LANGUAGE.md §8 "Text"). The shell's eyebrow carries the marker, so there is no gradient, badge, model name or word count here. --}}
@props(['text'])

@if (filled($text))
    <div {{ $attributes->class('prose prose-sm max-w-none text-base-content/80 line-clamp-5') }}>
        {!! str($text)->markdown() !!}
    </div>
@endif
