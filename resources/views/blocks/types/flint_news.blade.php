@props(['block'])

<div class="card bg-base-200 shadow hover:shadow-lg transition-all">
    <div class="card-body p-4 gap-3">
        <div class="flex items-start justify-between gap-2">
            <div class="badge badge-neutral badge-outline badge-sm gap-1">
                <x-icon name="fas.newspaper" class="w-3 h-3" />
                News Story
            </div>
            <div class="text-xs text-base-content/50">{{ $block->time?->diffForHumans() }}</div>
        </div>

        <h3 class="text-base font-semibold text-base-content">{{ $block->title }}</h3>

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
                    <div class="text-xs font-semibold uppercase tracking-wider text-base-content/50">Where accounts differ</div>
                    <p class="mt-1 text-sm text-base-content/75">{{ $news['contested'] }}</p>
                </div>
            @endif

            @if (!empty($news['sources']))
                <div class="space-y-1.5 rounded-lg bg-base-100 p-3">
                    <div class="text-xs font-semibold uppercase tracking-wider text-base-content/50">Reporting</div>
                    @foreach ($news['sources'] as $source)
                        @php($sourceHref = !empty($source['event_id']) ? route('events.show', $source['event_id']) : ($source['url'] ?? null))
                        <div class="text-sm text-base-content/75" wire:key="source-{{ $block->id }}-{{ $loop->index }}">
                            @if ($sourceHref)
                                <a
                                    href="{{ $sourceHref }}"
                                    class="link link-hover font-medium text-base-content"
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
                        <div class="text-xs font-semibold uppercase tracking-wider text-base-content/50">Why it matters</div>
                        <p class="mt-1 text-sm text-base-content/75">{{ $news['why_it_matters'] }}</p>
                    </div>
                @endif
                @if (!empty($news['what_to_watch']))
                    <div class="rounded-lg border border-base-300 p-3">
                        <div class="text-xs font-semibold uppercase tracking-wider text-base-content/50">What to watch</div>
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
                <span class="text-xs text-base-content/50">Sources</span>
                @foreach ($sources as $sourceId)
                    <a
                        href="{{ route('events.show', $sourceId) }}"
                        wire:navigate
                        class="badge badge-ghost badge-sm gap-1"
                        wire:key="src-{{ $sourceId }}"
                    >
                        <x-icon name="o-document-text" class="w-3 h-3" />
                        {{ $loop->iteration }}
                    </a>
                @endforeach
            </div>
        @endif

        <div class="flex items-center justify-between gap-2 pt-2 border-t border-base-300">
            <div class="flex items-center gap-1.5">
                <x-icon name="fas.hexagon-nodes" class="w-4 h-4 text-warning" />
                <span class="text-xs font-medium text-base-content/70">Flint</span>
            </div>
            <div class="flex items-center gap-2">
                @livewire('block-feedback', ['block' => $block], key('feedback-' . $block->id))
                <a href="{{ route('blocks.show', $block) }}" wire:navigate class="btn btn-ghost btn-xs gap-1">
                    <x-icon name="o-eye" class="w-3 h-3" />
                    View
                </a>
            </div>
        </div>
    </div>
</div>
