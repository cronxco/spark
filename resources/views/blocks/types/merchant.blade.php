@props(['block'])

@php
$merchantInfo = $block->metadata['merchant'] ?? [];
$merchantName = is_array($merchantInfo) ? ($merchantInfo['name'] ?? $block->title) : $merchantInfo;
$category = $block->metadata['category'] ?? null;
$address = $block->metadata['address'] ?? null;
$address = is_array($address) ? ($address['short_formatted'] ?? $address['formatted'] ?? null) : $address;
$logo = is_array($merchantInfo) ? ($merchantInfo['logo'] ?? null) : null;
@endphp

<x-block-card.shell :block="$block">
    <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-lg overflow-hidden bg-domain-money/10 flex items-center justify-center shrink-0">
            @if ($logo)
                <img src="{{ $logo }}" alt="" class="w-full h-full object-cover" loading="lazy">
            @else
                <x-icon name="fas.store" class="w-5 h-5 text-domain-money" />
            @endif
        </div>
        <div class="min-w-0">
            <div class="text-base font-semibold truncate">{{ $merchantName }}</div>
            @if ($category)
                <div class="text-xs text-base-content/70">{{ ucfirst(str_replace('_', ' ', $category)) }}</div>
            @endif
        </div>
    </div>
    @if (filled($address))
        <x-slot:context>{{ $address }}</x-slot:context>
    @endif
</x-block-card.shell>
