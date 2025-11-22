@props(['event', 'title', 'summary', 'url', 'image'])

@php
    $integration = $event->integration;

    // Determine display service based on fetch_mode for Fetch integration
    $displayService = $integration->service;
    if ($integration->service === 'fetch' && $event->target) {
        $fetchMode = $event->target->metadata['fetch_mode'] ?? 'recurring';
        if ($fetchMode === 'once') {
            $displayService = 'spark'; // Show as "Spark" for one-time fetches
        }
    }

    $serviceIcon = match($displayService) {
        'fetch' => 'fas.shield-halved',
        'spark' => 'fas.wand-magic-sparkles',
        'karakeep' => 'fas.bookmark',
        'bluesky' => 'fas.cloud',
        'reddit' => 'fas.comments',
        default => 'fas.link'
    };

    $accentColor = match($displayService) {
        'fetch' => 'info',
        'spark' => 'secondary',
        'karakeep' => 'warning',
        'bluesky' => 'accent',
        'reddit' => 'error',
        default => 'base-content'
    };

    // Determine content type and status indicator
    $blocks = $event->blocks;
    $contentStatus = 'error'; // Default: red (less than 100 words or no content)
    $contentTooltip = 'Limited content';

    if ($blocks->isNotEmpty()) {
        // Check for tweet summary (green)
        if ($blocks->where('block_type', 'fetch_summary_tweet')->isNotEmpty()) {
            $contentStatus = 'success';
            $contentTooltip = 'Summarised';
        }
        // Check for Karakeep AI summary (blue)
        elseif ($blocks->where('block_type', 'bookmark_summary')->isNotEmpty()) {
            $contentStatus = 'info';
            $contentTooltip = 'Provider summary';
        }
        // Check for raw content (amber if truncated or substantial)
        elseif ($rawContentBlock = $blocks->where('block_type', 'fetch_content')->first()) {
            $text = $rawContentBlock->metadata['text'] ?? '';
            $wordCount = str_word_count(strip_tags($text));
            if ($wordCount >= 100) {
                $contentStatus = 'warning';
                $contentTooltip = $wordCount > 1000 ? 'Content truncated' : 'Content available';
            }
        }
    }

    // Check if there's a linked_to relationship (URL discovered from this event's target)
    $hasLinkedUrl = false;
    if ($event->target) {
        $hasLinkedUrl = $event->target->relationshipsFrom()
        ->where('type', 'linked_to')
        ->exists();
    }
@endphp

<div class="card bg-base-200 shadow hover:shadow-lg transition-all">
    <div class="card-body p-4 gap-3">
        <!-- Header: Integration badge and date -->
        <div class="flex items-center justify-between gap-2 mb-1">
            <div class="badge badge-{{ $accentColor }} badge-outline badge-sm gap-1">
                <x-icon :name="$serviceIcon" class="w-3 h-3" />
                <span class="text-xs">{{ ucfirst($displayService) }}</span>
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
            <div class="text-sm text-base-content/70 line-clamp-5 leading-relaxed prose prose-sm max-w-none">
                {!! Str::markdown($summary) !!}
            </div>
        @endif

        <!-- Footer: Status indicator, URL and actions -->
        <div class="flex items-center gap-2 mt-2 pt-2 border-t border-base-300">
            <!-- Status indicator -->
            <div class="tooltip tooltip-top" data-tip="{{ $contentTooltip }}">
                <span class="status status-{{ $contentStatus }} status-sm"></span>
            </div>

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

            <!-- Actions dropdown -->
            <div class="dropdown dropdown-end">
                <label tabindex="0" class="btn btn-ghost btn-xs btn-square">
                    <x-icon name="fas.ellipsis-vertical" class="w-4 h-4" />
                </label>
                <ul tabindex="0" class="dropdown-content z-[1] menu p-2 shadow-lg bg-base-100 rounded-box w-52">
                    <li>
                        <a href="{{ route('events.show', $event->id) }}" wire:navigate class="text-sm gap-2">
                            <x-icon name="fas.arrow-right" class="w-4 h-4" />
                            View
                        </a>
                    </li>
                    @if ($hasLinkedUrl)
                    <li>
                        <a href="{{ route('events.show', $event->id) }}#linked-urls" wire:navigate class="text-sm gap-2">
                            <x-icon name="fas.shield-dog" class="w-4 h-4" />
                            Fetch
                        </a>
                    </li>
                    @endif
                    @if ($url)
                    <li>
                        <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="text-sm gap-2">
                            <x-icon name="o-arrow-top-right-on-square" class="w-4 h-4" />
                            Open Link
                        </a>
                    </li>
                    @endif
                </ul>
            </div>
        </div>
    </div>
</div>