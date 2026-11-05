@props(['block'])

@php
$counterparty = $block->metadata['counterparty'] ?? $block->title;
$sortCode = $block->metadata['sort_code'] ?? null;
$accountNumber = $block->metadata['account_number'] ?? null;
$masked = collect([
    $sortCode,
    $accountNumber ? '••' . substr($accountNumber, -4) : null,
])->filter()->join(' · ');
@endphp

<x-block-card.shell :block="$block">
    <div class="flex items-center gap-2 text-sm">
        <x-icon name="fas.arrow-right" class="w-3.5 h-3.5 text-base-content/70" />
        <span class="font-semibold truncate">{{ $counterparty }}</span>
    </div>
    <x-block-card.value :value="format_money($block->formatted_value ?? 0, $block->value_unit)" />
    @if ($masked !== '')
        <x-slot:context><span class="font-mono">{{ $masked }}</span></x-slot:context>
    @endif
</x-block-card.shell>
