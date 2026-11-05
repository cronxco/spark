@props(['block'])

@php
$url = $block->url ?? $block->title;
$domain = str_replace('www.', '', parse_url($url, PHP_URL_HOST) ?? '');
@endphp

<x-block-card.shell :block="$block" :title="$domain ?: $url">
    <p class="text-sm text-base-content/70 break-all line-clamp-2">{{ $url }}</p>
</x-block-card.shell>
