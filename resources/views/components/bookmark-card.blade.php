@props(['event', 'title', 'summary', 'url', 'image'])

@php
    $integration = $event->integration;
    $serviceIcon = match($integration->service) {
        'fetch' => 'o-shield-check',
        'karakeep' => 'o-bookmark',
        'bluesky' => 'o-cloud',
        'reddit' => 'o-chat-bubble-left-right',
        default => 'o-link'
    };

    $accentColor = match($integration->service) {
        'fetch' => 'info',
        'karakeep' => 'warning',
        'bluesky' => 'primary',
        'reddit' => 'error',
        default => 'base-content'
    };
@endphp

<div class="card bg-base-200 shadow hover:shadow-lg transition-all">
    <div class="card-body p-4 gap-3">
        <!-- Header: Integration badge and date -->
        <div class="flex items-center justify-between gap-2 mb-1">
            <div class="badge badge-{{ $accentColor }} badge-outline badge-sm gap-1">
                <x-icon :name="$serviceIcon" class="w-3 h-3" />
                <span class="text-xs">{{ ucfirst($integration->service) }}</span>
            </div>
            <div class="text-xs text-base-content/60">
                <x-uk-date :date="$event->time" :show-time="true" class="text-xs" />
            </div>
        </div>

        <!-- Image (if available) -->
        @if ($image)
            <div class="w-full h-48 rounded-lg overflow-hidden bg-base-300">
                <img src="{{ $image }}" alt="{{ $title }}" class="w-full h-full object-cover" loading="lazy" />
            </div>
        @endif

        <!-- Title -->
        <h3 class="font-semibold text-base leading-snug line-clamp-2">
            <a href="{{ route('events.show', $event->id) }}" wire:navigate class="link link-hover">
                {{ $title }}
            </a>
        </h3>

        <!-- Summary -->
        @if ($summary)
            <p class="text-sm text-base-content/70 line-clamp-3 leading-relaxed">
                {{ $summary }}
            </p>
        @endif

        <!-- Footer: URL and action -->
        <div class="flex items-center gap-2 mt-2 pt-2 border-t border-base-300">
            @if ($url)
                <div class="flex-1 min-w-0">
                    <a href="{{ $url }}" target="_blank" rel="noopener noreferrer"
                       class="text-xs text-base-content/50 hover:text-primary truncate block">
                        {{ parse_url($url, PHP_URL_HOST) }}
                    </a>
                </div>
            @else
                <div class="flex-1"></div>
            @endif
            <a href="{{ route('events.show', $event->id) }}" wire:navigate
               class="btn btn-ghost btn-xs gap-1">
                <span class="text-xs">View</span>
                <x-icon name="o-arrow-right" class="w-3 h-3" />
            </a>
        </div>
    </div>
</div>
