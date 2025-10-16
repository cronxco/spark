@props(['tag', 'full' => false, 'fill' => false, 'size' => 'sm'])

@php
// Handle both objects and arrays
$isObject = is_object($tag);
$isArray = is_array($tag);

if ($isObject) {
$tagName = is_array($tag->name ?? null) ? ($tag->name['en'] ?? $tag->name[0] ?? '') : (string)($tag->name ?? '');
$tagType = $tag->type ?? null;
$tagId = $tag->id ?? null;
$tagSlug = is_array($tag->slug ?? null) ? ($tag->slug['en'] ?? $tag->slug[0] ?? null) : ($tag->slug ?? null);
} elseif ($isArray) {
$tagName = is_array($tag['name'] ?? null) ? ($tag['name']['en'] ?? $tag['name'][0] ?? '') : (string)($tag['name'] ?? '');
$tagType = $tag['type'] ?? null;
$tagId = $tag['id'] ?? null;
$tagSlug = is_array($tag['slug'] ?? null) ? ($tag['slug']['en'] ?? $tag['slug'][0] ?? null) : ($tag['slug'] ?? null);
} else {
$tagName = (string)$tag;
$tagType = null;
$tagId = null;
$tagSlug = null;
}

$uuid = 'tag-' . md5($tagName . ($tagId ?? ''));

// Truncate long tag names
$displayName = $tagName;
if (!$full && strlen($tagName) > 20) {
$spacePos = strpos($tagName, ' ');
$cut = $spacePos === false || $spacePos > 20;
$wrapped = wordwrap($tagName, 20, ';;', $cut);
$parts = explode(';;', $wrapped);
$displayName = $parts[0] . '...';
}

// Determine popover color based on badge color
$color = match($tagType) {
'transaction_category', 'transaction_type', 'transaction_status', 'transaction_scheme',
'transaction_currency', 'balance_type', 'card_pan', 'decline_reason', 'merchant_country', 'merchant_category', 'music_album', 'spotify_context', 'karakeep' => 'info',
'music_artist', 'person' => 'secondary',
'emoji', 'merchant_emoji' => 'warning',
'spark' => 'primary',
default => 'info',
};

// Determine badge color based on tag type
$badgeClass = "badge-{$color}";

if (!$fill) {
$badgeClass .= ' badge-outline';
}

// Add size class
$sizeClass = match($size) {
'xs' => 'badge-xs',
'sm' => 'badge-sm',
'md' => 'badge-md',
'lg' => 'badge-lg',
default => 'badge-sm',
};

$badgeClass .= ' ' . $sizeClass;
@endphp

<x-popover class="inline-block bg-{{ $color }}">
    <x-slot:trigger>
        <x-badge class="{{ $badgeClass }}">
            <x-slot:value>
                {{ $displayName }}
            </x-slot:value>
        </x-badge>
    </x-slot:trigger>
    <x-slot:content class="bg-{{ $color }} text-{{ $color }}-content text-center border-{{ $color }}/40">@if ($tagType && $tagSlug && $tagId)<a href="{{ route('tags.show', [$tagType, $tagSlug, $tagId]) }}"><x-fas-tags class="w-4 h-4 inline" /> {{ Str::headline($tagType ?? 'Tag') }}<br /><span class="font-bold text-base text-center">{{ $tagName }}</span></a>@else<div><x-fas-tags class="w-4 h-4 inline" /> {{ Str::headline($tagType ?? 'Tag') }}<br /><span class="font-bold text-base text-center">{{ $tagName }}</span></div>@endif</x-slot:content>
</x-popover>