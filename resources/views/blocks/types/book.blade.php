@props(['block'])

@php
use App\Integrations\PluginRegistry;

$book = $block->event->target;
$metadata = array_merge($block->metadata ?? [], $book->metadata ?? []);
$bookTitle = $book->title ?? $block->title;
$authorName = $metadata['author'] ?? null;
$seriesName = $metadata['series_name'] ?? null;
$seriesNumber = $metadata['series_number'] ?? null;
$dateRead = $metadata['date_read'] ?? null;
$currentProgress = $metadata['current_progress'] ?? null;

// A book with a read date is finished, whatever the progress field says.
if ($dateRead) {
    $currentProgress = 100;
}

$pluginClass = PluginRegistry::getPlugin($block->event->service);
$actionType = $pluginClass ? ($pluginClass::getActionTypes()[$block->event->action] ?? null) : null;
$actionLabel = $actionType['display_name'] ?? null;

$hasImage = $block->getFirstMedia('downloaded_images') !== null;
@endphp

<x-block-card.shell :block="$block" :label="$actionLabel" :title="$bookTitle">
    <div class="flex gap-3">
        <div class="w-20 shrink-0 aspect-[2/3] rounded-lg overflow-hidden bg-domain-knowledge/10 flex items-center justify-center">
            @if ($hasImage)
                {!! render_media_responsive($block, 'downloaded_images', [
                    'alt' => '',
                    'class' => 'w-full h-full object-cover',
                    'loading' => 'lazy',
                ]) !!}
            @else
                <x-icon name="fas.book" class="w-6 h-6 text-domain-knowledge" />
            @endif
        </div>

        <div class="min-w-0 flex-1 space-y-2">
            @if ($authorName)
                <div class="text-sm text-base-content/70">{{ $authorName }}</div>
            @endif

            <x-block-card.stats class="!grid-cols-2" :stats="[
                'Published' => $metadata['published_year'] ?? null,
                'Pages' => is_numeric($metadata['num_pages'] ?? null) ? number_format($metadata['num_pages']) : null,
                'Your rating' => is_numeric($metadata['user_rating'] ?? null) ? number_format($metadata['user_rating'], 1) : null,
                'Average' => is_numeric($metadata['average_rating'] ?? null) ? number_format($metadata['average_rating'], 2) : null,
            ]" />

            @if ($currentProgress !== null && $currentProgress < 100)
                <x-block-card.bar :percent="$currentProgress" label="Read so far" tint="bg-domain-knowledge" />
            @endif
        </div>
    </div>

    @if ($seriesName || $dateRead)
        <x-slot:context>
            {{ collect([
                $seriesName ? $seriesName . ($seriesNumber ? ' #' . $seriesNumber : '') : null,
                $dateRead ? 'Read ' . \Carbon\Carbon::parse($dateRead)->format('j M Y') : null,
            ])->filter()->join(' · ') }}
        </x-slot:context>
    @endif
</x-block-card.shell>
