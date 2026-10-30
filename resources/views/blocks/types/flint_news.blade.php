@props(['block'])

<x-block-card.shell :block="$block" :title="$block->title">
    @php($news = $block->metadata['news'] ?? null)
    @if (is_array($news))
        @if (!empty($news['key_points']))
            <p class="text-sm leading-relaxed text-base-content/80">{{ $block->getContent() }}</p>
            <ul class="list-disc space-y-1 pl-5 text-sm text-base-content/80">
                @foreach ($news['key_points'] as $point)
                    <li wire:key="point-{{ $block->id }}-{{ $loop->index }}">{{ $point }}</li>
                @endforeach
            </ul>
        @else
            <p class="text-sm leading-relaxed text-base-content/80">{{ $news['summary'] ?? $block->getContent() }}</p>
        @endif

        @if (!empty($news['contested']))
            <div class="rounded-lg border border-base-300 p-3">
                <div class="text-xs font-semibold text-base-content/70">Where accounts differ</div>
                <p class="mt-1 text-sm text-base-content/75">{{ $news['contested'] }}</p>
            </div>
        @endif

        @if (!empty($news['sources']))
            <div class="space-y-1.5 rounded-lg bg-base-100 p-3">
                <div class="text-xs font-semibold text-base-content/70">Reporting</div>
                @foreach ($news['sources'] as $source)
                    @php($sourceHref = !empty($source['event_id']) ? route('events.show', $source['event_id']) : ($source['url'] ?? null))
                    <div class="text-sm text-base-content/75" wire:key="source-{{ $block->id }}-{{ $loop->index }}">
                        @if ($sourceHref)
                            <a
                                href="{{ $sourceHref }}"
                                class="relative z-10 link link-hover font-medium text-base-content"
                                @if (empty($source['event_id'])) target="_blank" rel="noopener" @else wire:navigate @endif
                            >{{ $source['publication'] ?? 'Source' }}</a>
                        @else
                            <span class="font-medium text-base-content">{{ $source['publication'] ?? 'Source' }}</span>
                        @endif
                        @if (($source['origin'] ?? null) === 'research')
                            <span class="badge badge-ghost badge-xs">Further reading</span>
                        @endif
                        — {{ $source['position'] ?? '' }}
                    </div>
                @endforeach
            </div>
        @endif

        <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
            @if (!empty($news['why_it_matters']))
                <div class="rounded-lg border border-base-300 p-3">
                    <div class="text-xs font-semibold text-base-content/70">Why it matters</div>
                    <p class="mt-1 text-sm text-base-content/75">{{ $news['why_it_matters'] }}</p>
                </div>
            @endif
            @if (!empty($news['what_to_watch']))
                <div class="rounded-lg border border-base-300 p-3">
                    <div class="text-xs font-semibold text-base-content/70">What to watch</div>
                    <p class="mt-1 text-sm text-base-content/75">{{ $news['what_to_watch'] }}</p>
                </div>
            @endif
        </div>
    @elseif ($block->getContent())
        <div class="prose prose-sm max-w-none text-base-content/80">
            {!! $block->getContentAsHtml() !!}
        </div>
    @endif

    @php($sources = $block->metadata['referenced_event_ids'] ?? [])
    @if (count($sources))
        <div class="flex flex-wrap items-center gap-1.5 pt-1">
            <span class="text-xs text-base-content/70">Sources</span>
            @foreach ($sources as $sourceId)
                <a
                    href="{{ route('events.show', $sourceId) }}"
                    wire:navigate
                    class="relative z-10 badge badge-ghost badge-sm gap-1"
                    wire:key="src-{{ $sourceId }}"
                >
                    <x-icon name="fas.file-lines" class="w-3 h-3" />
                    {{ $loop->iteration }}
                </a>
            @endforeach
        </div>
    @endif

    <x-slot:context>
        <div class="relative z-10 flex items-center gap-2">
            @livewire('block-feedback', ['block' => $block], key('feedback-' . $block->id))
        </div>
    </x-slot:context>
</x-block-card.shell>
