@props(['media'])

<div class="card bg-base-200 shadow hover:shadow-lg transition-all group">
    <div class="card-body p-4 gap-3">
        {{-- Header: Collection Badge and Date --}}
        <div class="flex items-center justify-between gap-2 mb-1">
            <div class="badge badge-primary badge-outline badge-sm gap-1">
                @if (str_starts_with($media->mime_type, 'image/'))
                    <x-icon name="o-photo" class="w-3 h-3" />
                @elseif (str_starts_with($media->mime_type, 'video/'))
                    <x-icon name="o-video-camera" class="w-3 h-3" />
                @elseif (str_starts_with($media->mime_type, 'application/pdf'))
                    <x-icon name="o-document-text" class="w-3 h-3" />
                @else
                    <x-icon name="o-document" class="w-3 h-3" />
                @endif
                <span class="text-xs">{{ ucfirst(str_replace('_', ' ', $media->collection_name ?? 'media')) }}</span>
            </div>
            <div class="text-xs text-base-content/60">
                <x-uk-date :date="$media->created_at" :show-time="false" class="text-xs" />
            </div>
        </div>

        {{-- Media Preview --}}
        <a href="{{ route('media.show', $media->id) }}" wire:navigate class="block">
            <div class="w-full h-48 rounded-lg overflow-hidden bg-base-300 relative">
                @if (str_starts_with($media->mime_type, 'video/'))
                    {{-- Video Preview --}}
                    <div class="relative w-full h-full">
                        @if ($media->hasGeneratedConversion('thumbnail'))
                            <img
                                src="{{ $media->getUrl('thumbnail') }}"
                                alt="{{ $media->name }}"
                                class="w-full h-full object-cover"
                                loading="lazy"
                            />
                        @else
                            <div class="flex items-center justify-center w-full h-full">
                                <x-icon name="o-video-camera" class="w-12 h-12 text-base-content/30" />
                            </div>
                        @endif
                        <div class="absolute inset-0 flex items-center justify-center">
                            <div class="w-12 h-12 rounded-full bg-black/50 flex items-center justify-center">
                                <x-icon name="o-play" class="w-6 h-6 text-white ml-1" />
                            </div>
                        </div>
                    </div>
                @elseif (str_starts_with($media->mime_type, 'image/'))
                    {{-- Image Preview --}}
                    @if ($media->hasGeneratedConversion('thumbnail'))
                        <img
                            src="{{ $media->getUrl('thumbnail') }}"
                            alt="{{ $media->name }}"
                            class="w-full h-full object-cover group-hover:scale-105 transition-transform"
                            loading="lazy"
                        />
                    @else
                        <img
                            src="{{ $media->getUrl() }}"
                            alt="{{ $media->name }}"
                            class="w-full h-full object-cover group-hover:scale-105 transition-transform"
                            loading="lazy"
                        />
                    @endif
                @elseif (str_starts_with($media->mime_type, 'application/pdf'))
                    {{-- PDF Preview --}}
                    <div class="flex flex-col items-center justify-center w-full h-full">
                        <x-icon name="o-document-text" class="w-12 h-12 text-base-content/30 mb-2" />
                        <span class="text-xs text-base-content/50">PDF Document</span>
                    </div>
                @else
                    {{-- Generic Document Preview --}}
                    <div class="flex flex-col items-center justify-center w-full h-full">
                        <x-icon name="o-document" class="w-12 h-12 text-base-content/30 mb-2" />
                        <span class="text-xs text-base-content/50">{{ Str::upper(Str::after($media->mime_type, '/')) }}</span>
                    </div>
                @endif
            </div>
        </a>

        {{-- Title --}}
        <h3 class="font-semibold text-sm leading-snug line-clamp-2">
            <a href="{{ route('media.show', $media->id) }}" wire:navigate class="link link-hover">
                {{ $media->name ?: $media->file_name }}
            </a>
        </h3>

        {{-- Metadata Badges --}}
        <div class="flex flex-wrap gap-1">
            @if ($media->model)
                <div class="badge badge-ghost badge-xs">
                    {{ class_basename($media->model_type) }}
                </div>
            @endif
            <div class="badge badge-ghost badge-xs">
                {{ Str::after($media->mime_type, '/') }}
            </div>
            <div class="badge badge-ghost badge-xs">
                {{ $media->humanReadableSize }}
            </div>
        </div>

        {{-- Footer: Actions --}}
        <div class="flex items-center gap-2 mt-2 pt-2 border-t border-base-300">
            <div class="flex-1"></div>

            {{-- Quick Download --}}
            <a
                href="{{ $media->getUrl() }}"
                download="{{ $media->file_name }}"
                class="btn btn-ghost btn-xs btn-square"
                title="Download"
            >
                <x-icon name="o-arrow-down-tray" class="w-4 h-4" />
            </a>

            {{-- Actions Dropdown --}}
            <div class="dropdown dropdown-end">
                <label tabindex="0" class="btn btn-ghost btn-xs btn-square">
                    <x-icon name="o-ellipsis-vertical" class="w-4 h-4" />
                </label>
                <ul tabindex="0" class="dropdown-content z-[1] menu p-2 shadow-lg bg-base-100 rounded-box w-52">
                    <li>
                        <a href="{{ route('media.show', $media->id) }}" wire:navigate class="text-sm gap-2">
                            <x-icon name="o-eye" class="w-4 h-4" />
                            View Details
                        </a>
                    </li>
                    @if ($media->model)
                        <li>
                            @if ($media->model instanceof \App\Models\EventObject)
                                <a href="{{ route('objects.show', $media->model->id) }}" wire:navigate class="text-sm gap-2">
                                    <x-icon name="o-arrow-right" class="w-4 h-4" />
                                    View {{ class_basename($media->model_type) }}
                                </a>
                            @elseif ($media->model instanceof \App\Models\Block)
                                <a href="{{ route('blocks.show', $media->model->id) }}" wire:navigate class="text-sm gap-2">
                                    <x-icon name="o-arrow-right" class="w-4 h-4" />
                                    View {{ class_basename($media->model_type) }}
                                </a>
                            @elseif ($media->model instanceof \App\Models\Event)
                                <a href="{{ route('events.show', $media->model->id) }}" wire:navigate class="text-sm gap-2">
                                    <x-icon name="o-arrow-right" class="w-4 h-4" />
                                    View {{ class_basename($media->model_type) }}
                                </a>
                            @endif
                        </li>
                    @endif
                    <li>
                        <a href="{{ $media->getUrl() }}" target="_blank" class="text-sm gap-2">
                            <x-icon name="o-arrow-top-right-on-square" class="w-4 h-4" />
                            Open in New Tab
                        </a>
                    </li>
                    <li>
                        <button onclick="navigator.clipboard.writeText('{{ $media->getUrl() }}')" class="text-sm gap-2">
                            <x-icon name="o-clipboard" class="w-4 h-4" />
                            Copy URL
                        </button>
                    </li>
                    <li>
                        <button onclick="navigator.clipboard.writeText('{{ $media->id }}')" class="text-sm gap-2">
                            <x-icon name="o-hashtag" class="w-4 h-4" />
                            Copy ID
                        </button>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>
