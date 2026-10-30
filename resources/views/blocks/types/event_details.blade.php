@props(['block'])

@php
$status = $block->metadata['status'] ?? null;
$creator = $block->metadata['creator'] ?? null;
$organizer = $block->metadata['organizer'] ?? null;
@endphp

<x-block-card.shell :block="$block" :title="$block->title">
    <div class="space-y-2 text-sm">
        @if ($status)
        <div class="flex items-center justify-between">
            <span class="text-base-content/70">Status</span>
            <span>{{ ucfirst($status) }}</span>
        </div>
        @endif
        @if (is_array($organizer) && isset($organizer['email']))
        <div class="flex items-center justify-between">
            <span class="text-base-content/70">Organizer</span>
            <span class="text-right truncate ml-2">{{ $organizer['displayName'] ?? $organizer['email'] }}</span>
        </div>
        @elseif (is_string($organizer))
        <div class="flex items-center justify-between">
            <span class="text-base-content/70">Organizer</span>
            <span class="text-right truncate ml-2">{{ $organizer }}</span>
        </div>
        @endif
        @if (is_array($creator) && isset($creator['email']) && $creator['email'] !== ($organizer['email'] ?? null))
        <div class="flex items-center justify-between">
            <span class="text-base-content/70">Creator</span>
            <span class="text-right truncate ml-2">{{ $creator['displayName'] ?? $creator['email'] }}</span>
        </div>
        @endif
    </div>
</x-block-card.shell>
