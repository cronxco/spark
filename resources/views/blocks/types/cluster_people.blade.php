@props(['block'])

@php
$people = collect($block->metadata['people'] ?? []);
@endphp

<x-block-card.shell :block="$block">
    @if ($people->isNotEmpty())
        <ul class="space-y-1.5">
            @foreach ($people->take(5) as $person)
                <li class="flex items-center justify-between gap-2 text-sm">
                    <span class="flex items-center gap-2 min-w-0">
                        <span class="w-7 h-7 rounded-full bg-domain-media/10 text-domain-media-ink flex items-center justify-center text-xs font-semibold shrink-0" aria-hidden="true">
                            {{ mb_substr($person['name'] ?? '?', 0, 1, 'UTF-8') }}
                        </span>
                        <span class="font-medium truncate">{{ $person['name'] ?? 'Unknown' }}</span>
                    </span>
                    <span class="text-xs text-base-content/70 tabular-nums shrink-0">{{ number_format($person['photo_count'] ?? 0) }} photos</span>
                </li>
            @endforeach
        </ul>
        @if ($people->count() > 5)
            <x-slot:context>+{{ $people->count() - 5 }} more</x-slot:context>
        @endif
    @else
        <p class="text-sm text-base-content/70">No one recognised in these photos</p>
    @endif
</x-block-card.shell>
