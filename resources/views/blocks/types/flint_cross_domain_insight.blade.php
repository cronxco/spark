@props(['block'])

@php
use App\Integrations\PluginRegistry;
use App\Models\Event;

$pluginClass = PluginRegistry::getPlugin($block->event->service);
$serviceName = $pluginClass ? $pluginClass::getDisplayName() : ucfirst($block->event->service);
$icon = $pluginClass ? $pluginClass::getIcon() : 'o-sparkles';
$accentColor = $pluginClass ? $pluginClass::getAccentColor() : 'primary';

$domains = $block->metadata['domains'] ?? [];
$observation = $block->metadata['observation'] ?? '';
$referencedEventIds = $block->metadata['referenced_event_ids'] ?? [];
$confidence = $block->metadata['confidence'] ?? 0.7;

// Load referenced events (filter out invalid UUIDs from legacy data)
$referencedEvents = [];
if (!empty($referencedEventIds)) {
    // Filter to only valid UUIDs
    $validUuids = array_filter($referencedEventIds, function($id) {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id);
    });

    if (!empty($validUuids)) {
        $referencedEvents = Event::whereIn('id', $validUuids)->get();
    }
}

// Domain colors
$domainColors = [
    'health' => 'success',
    'money' => 'warning',
    'media' => 'secondary',
    'knowledge' => 'accent',
    'online' => 'info',
];

// Map accent color to static Tailwind class
$iconColorClass = match($accentColor) {
    'primary' => 'text-primary',
    'secondary' => 'text-secondary',
    'accent' => 'text-accent',
    'success' => 'text-success',
    'warning' => 'text-warning',
    'error' => 'text-error',
    'info' => 'text-info',
    'neutral' => 'text-neutral',
    default => 'text-primary',
};

// Map domain colors to static badge classes
$domainBadgeClasses = [];
foreach ($domains as $domain) {
    $color = $domainColors[$domain] ?? 'ghost';
    $domainBadgeClasses[$domain] = match($color) {
        'primary' => 'badge-primary',
        'secondary' => 'badge-secondary',
        'accent' => 'badge-accent',
        'success' => 'badge-success',
        'warning' => 'badge-warning',
        'error' => 'badge-error',
        'info' => 'badge-info',
        'neutral' => 'badge-neutral',
        'ghost' => 'badge-ghost',
        default => 'badge-ghost',
    };
}

// Map confidence to static progress class
$progressColorClass = match(true) {
    $confidence >= 0.8 => 'progress-success',
    $confidence >= 0.6 => 'progress-warning',
    default => 'progress-error',
};
@endphp

<div class="card bg-gradient-to-br from-primary/5 to-secondary/5 shadow hover:shadow-lg transition-all border border-primary/20">
    <div class="card-body p-4 gap-3">
        {{-- Header --}}
        <div class="flex items-start justify-between gap-2">
            <div class="flex items-center gap-2 flex-wrap">
                <div class="badge badge-primary badge-outline badge-sm gap-1">
                    <x-icon name="o-arrows-right-left" class="w-3 h-3" />
                    Cross-Domain
                </div>
                @foreach ($domains as $domain)
                    <div class="badge {{ $domainBadgeClasses[$domain] ?? 'badge-ghost' }} badge-outline badge-sm">
                        {{ ucfirst($domain) }}
                    </div>
                @endforeach
            </div>
            <div class="text-xs text-base-content/50">
                {{ $block->time->diffForHumans() }}
            </div>
        </div>

        {{-- Title --}}
        <h3 class="text-base font-semibold text-base-content line-clamp-2">
            {{ $block->title }}
        </h3>

        {{-- Confidence meter --}}
        <div class="flex items-center gap-2">
            <div class="text-xs text-base-content/70">Confidence:</div>
            <div class="flex-1">
                <progress
                    class="progress {{ $progressColorClass }} w-full h-2"
                    value="{{ $confidence * 100 }}"
                    max="100"
                ></progress>
            </div>
            <div class="text-xs font-medium text-base-content/70">{{ round($confidence * 100) }}%</div>
        </div>

        {{-- Observation --}}
        @if ($observation)
        <div class="text-sm text-base-content/80 bg-base-100 rounded-lg p-3 border border-primary/20">
            <div class="flex gap-2">
                <x-icon name="o-light-bulb" class="w-4 h-4 text-primary flex-shrink-0 mt-0.5" />
                <div>{{ $observation }}</div>
            </div>
        </div>
        @endif

        {{-- Referenced Events --}}
        @if (!empty($referencedEvents) && $referencedEvents->count() > 0)
        <div class="space-y-2">
            <div class="text-xs font-medium text-base-content/60">Connecting:</div>
            <div class="flex flex-wrap gap-1.5">
                @foreach ($referencedEvents as $refEvent)
                    <x-event-ref :event="$refEvent" :showService="true" />
                @endforeach
            </div>
        </div>
        @endif

        {{-- Footer --}}
        <div class="flex items-center justify-between gap-2 pt-2 border-t border-primary/20">
            <div class="flex items-center gap-1.5">
                <x-icon :name="$icon" class="w-4 h-4 {{ $iconColorClass }}" />
                <span class="text-xs font-medium text-base-content/70">{{ $serviceName }}</span>
            </div>

            <div class="flex items-center gap-2">
                @livewire('block-feedback', ['block' => $block], key('feedback-' . $block->id))

                <a
                    href="{{ route('blocks.show', $block) }}"
                    wire:navigate
                    class="btn btn-ghost btn-xs gap-1"
                >
                    <x-icon name="o-eye" class="w-3 h-3" />
                    View
                </a>
            </div>
        </div>
    </div>
</div>
