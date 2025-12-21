@props(['block'])

@php
use App\Integrations\PluginRegistry;
use App\Models\Event;

$pluginClass = PluginRegistry::getPlugin($block->event->service);
$serviceName = $pluginClass ? $pluginClass::getDisplayName() : ucfirst($block->event->service);
$icon = $pluginClass ? $pluginClass::getIcon() : 'o-sparkles';
$accentColor = $pluginClass ? $pluginClass::getAccentColor() : 'primary';

$type = $block->metadata['type'] ?? 'observation';
$description = $block->metadata['description'] ?? '';
$supportingData = $block->metadata['supporting_data'] ?? [];
$referencedEventIds = $block->metadata['referenced_event_ids'] ?? [];
$confidence = $block->metadata['confidence'] ?? 0.7;

// Load referenced events
$referencedEvents = [];
if (!empty($referencedEventIds)) {
    $referencedEvents = Event::whereIn('id', $referencedEventIds)->get();
}

// Type icons and colors
$typeConfig = match($type) {
    'pattern' => ['icon' => 'o-chart-bar', 'color' => 'info', 'label' => 'Pattern'],
    'anomaly' => ['icon' => 'o-exclamation-triangle', 'color' => 'warning', 'label' => 'Anomaly'],
    'trend' => ['icon' => 'o-arrow-trending-up', 'color' => 'success', 'label' => 'Trend'],
    default => ['icon' => 'o-light-bulb', 'color' => 'primary', 'label' => 'Observation'],
};

// Map type color to static badge class
$typeBadgeClass = match($typeConfig['color']) {
    'primary' => 'badge-primary',
    'secondary' => 'badge-secondary',
    'accent' => 'badge-accent',
    'success' => 'badge-success',
    'warning' => 'badge-warning',
    'error' => 'badge-error',
    'info' => 'badge-info',
    default => 'badge-primary',
};

// Map accent color to static text class
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

// Map confidence to static progress class
$progressColorClass = match(true) {
    $confidence >= 0.8 => 'progress-success',
    $confidence >= 0.6 => 'progress-warning',
    default => 'progress-error',
};
@endphp

<div class="card bg-base-200 shadow hover:shadow-lg transition-all">
    <div class="card-body p-4 gap-3">
        {{-- Header --}}
        <div class="flex items-start justify-between gap-2">
            <div class="flex items-center gap-2 flex-wrap">
                <div class="badge {{ $typeBadgeClass }} badge-outline badge-sm gap-1">
                    <x-icon :name="$typeConfig['icon']" class="w-3 h-3" />
                    {{ $typeConfig['label'] }}
                </div>
                <div class="badge badge-secondary badge-outline badge-sm">Media</div>
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

        {{-- Description --}}
        @if ($description)
        <div class="text-sm text-base-content/80 bg-base-100 rounded-lg p-3 border border-base-300">
            {{ $description }}
        </div>
        @endif

        {{-- Supporting Data --}}
        @if (!empty($supportingData))
        <div class="space-y-1">
            <div class="text-xs font-medium text-base-content/60">Supporting Evidence:</div>
            <ul class="text-xs text-base-content/70 space-y-1 ml-4">
                @foreach ($supportingData as $point)
                    <li class="list-disc">{{ $point }}</li>
                @endforeach
            </ul>
        </div>
        @endif

        {{-- Referenced Events --}}
        @if (!empty($referencedEvents) && $referencedEvents->count() > 0)
        <div class="space-y-2">
            <div class="text-xs font-medium text-base-content/60">Source Data:</div>
            <div class="flex flex-wrap gap-1.5">
                @foreach ($referencedEvents as $refEvent)
                    <x-event-ref :event="$refEvent" :showService="true" />
                @endforeach
            </div>
        </div>
        @endif

        {{-- Footer --}}
        <div class="flex items-center justify-between gap-2 pt-2 border-t border-base-300">
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
