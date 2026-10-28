{{-- A model's takeaways as a list (DESIGN_LANGUAGE.md §8 "List"): up to five, then "+N more". --}}
@props(['items', 'limit' => 5])

@php
$items = collect(is_string($items) ? preg_split('/[\n\r]+/', $items) : (array) $items)
    ->map(fn ($item) => is_string($item) ? $item : ($item['text'] ?? $item['content'] ?? ''))
    ->map(fn ($item) => trim(preg_replace('/^\s*(?:[-*•]|\d+[.)])\s+/u', '', $item)))
    ->filter()
    ->values();
@endphp

@if ($items->isNotEmpty())
    <ul {{ $attributes->class('space-y-1.5 text-sm text-base-content/80') }}>
        @foreach ($items->take($limit) as $item)
            <li class="flex gap-2 items-start">
                <x-icon name="fas.check" class="w-3 h-3 mt-1 shrink-0 text-base-content/70" />
                <span class="[&_p]:inline">{!! str($item)->inlineMarkdown() !!}</span>
            </li>
        @endforeach
    </ul>
    @if ($items->count() > $limit)
        <div class="text-xs text-base-content/70">+{{ $items->count() - $limit }} more</div>
    @endif
@endif
