<div>
    @if ($this->media)
        <div class="flex flex-col lg:flex-row gap-4 lg:gap-6">
            {{-- Main Content Area --}}
            <div class="flex-1 space-y-4 lg:space-y-6">
                {{-- Header with Actions --}}
                <x-header title="Media Details" separator>
                    <x-slot:actions>
                        <div class="flex items-center gap-2">
                            <x-button
                                icon="fas.pen"
                                class="btn-ghost btn-sm"
                                wire:click="openEditModal"
                                title="Edit media"
                            />
                            <x-button
                                icon="fas.download"
                                class="btn-ghost btn-sm"
                                link="{{ $mediaUrl }}"
                                external
                                title="Download"
                            />
                            <x-button
                                icon="fas.trash"
                                class="btn-ghost btn-sm text-error"
                                wire:click="openDeleteConfirm"
                                title="Delete media"
                            />
                            <x-button
                                wire:click="toggleSidebar"
                                class="btn-ghost btn-sm"
                                title="{{ $this->showSidebar ? 'Hide details' : 'Show details' }}"
                                data-hotkey="d"
                            >
                                <x-icon name="fas.sliders" class="w-4 h-4" />
                            </x-button>
                        </div>
                    </x-slot:actions>
                </x-header>

                {{-- Overview Card --}}
                <x-card class="bg-base-200 shadow">
                    <div class="flex flex-col sm:flex-row items-start gap-4 lg:gap-6">
                        <div class="flex-shrink-0">
                            <div class="w-16 h-16 rounded-full bg-base-300 flex items-center justify-center">
                                @if (str_starts_with($this->media->mime_type, 'image/'))
                                    <x-icon name="fas.image" class="w-8 h-8" />
                                @elseif (str_starts_with($this->media->mime_type, 'video/'))
                                    <x-icon name="o-video-camera" class="w-8 h-8" />
                                @elseif (str_starts_with($this->media->mime_type, 'application/pdf'))
                                    <x-icon name="fas.file-lines" class="w-8 h-8" />
                                @else
                                    <x-icon name="fas.file" class="w-8 h-8" />
                                @endif
                            </div>
                        </div>

                        <div class="flex-1">
                            <h2 class="text-2xl lg:text-3xl font-bold text-base-content mb-2">
                                {{ $this->media->name ?: $this->media->file_name }}
                            </h2>

                            <div class="flex flex-wrap items-center gap-3 mb-4">
                                <div class="flex items-center gap-2">
                                    <x-icon name="fas.clock" class="w-4 h-4 text-base-content/60" />
                                    <span class="text-base-content/70 text-sm">
                                        <x-uk-date :date="$this->media->created_at" :show-time="true" />
                                    </span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <x-icon name="fas.download" class="w-4 h-4 text-base-content/60" />
                                    <span class="text-base-content/70 text-sm">{{ $this->media->humanReadableSize }}</span>
                                </div>
                            </div>

                            {{-- Badges --}}
                            <div class="flex flex-wrap gap-2">
                                <div class="badge badge-primary">
                                    {{ ucfirst(str_replace('_', ' ', $this->media->collection_name ?? 'media')) }}
                                </div>
                                <div class="badge badge-ghost">
                                    {{ $this->media->mime_type }}
                                </div>
                                @if ($this->media->model)
                                    <div class="badge badge-secondary">
                                        {{ class_basename($this->media->model_type) }}
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </x-card>

                {{-- Media Preview --}}
                <x-card class="bg-base-100 shadow">
                    <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                        <x-icon name="fas.eye" class="w-5 h-5 text-info" />
                        Preview
                    </h3>

                    <div class="flex justify-center">
                        @if (str_starts_with($this->media->mime_type, 'video/'))
                            {{-- Video Player --}}
                            <video
                                src="{{ $mediaUrl }}"
                                class="max-w-full max-h-[600px] rounded-lg"
                                controls
                            ></video>
                        @elseif (str_starts_with($this->media->mime_type, 'image/'))
                            {{-- Image Preview --}}
                            <div class="relative">
                                <img
                                    src="{{ $mediaUrl }}"
                                    alt="{{ $this->media->name }}"
                                    class="max-w-full max-h-[600px] rounded-lg"
                                />
                                @if ($this->media->getCustomProperty('width') && $this->media->getCustomProperty('height'))
                                    <div class="absolute bottom-2 right-2 badge badge-sm bg-black/70 text-white">
                                        {{ $this->media->getCustomProperty('width') }} × {{ $this->media->getCustomProperty('height') }}
                                    </div>
                                @endif
                            </div>
                        @elseif (str_starts_with($this->media->mime_type, 'application/pdf'))
                            {{-- PDF Preview --}}
                            <div class="w-full">
                                <iframe
                                    src="{{ $mediaUrl }}"
                                    class="w-full h-[600px] rounded-lg border border-base-300"
                                ></iframe>
                            </div>
                        @else
                            {{-- Generic Document --}}
                            <div class="flex flex-col items-center justify-center py-12">
                                <x-icon name="fas.file" class="w-16 h-16 text-base-content/30 mb-4" />
                                <p class="text-base-content/70 mb-4">Preview not available for this file type</p>
                                <a href="{{ $mediaUrl }}" target="_blank" class="btn btn-primary btn-sm">
                                    <x-icon name="o-arrow-top-right-on-square" class="w-4 h-4" />
                                    Open in New Tab
                                </a>
                            </div>
                        @endif
                    </div>
                </x-card>

                {{-- Related Model --}}
                @if ($this->media->model)
                    <x-card class="bg-base-100 shadow">
                        <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                            <x-icon name="fas.link" class="w-5 h-5 text-warning" />
                            Related {{ class_basename($this->media->model_type) }}
                        </h3>

                        <div class="p-4 bg-base-200 rounded-lg">
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex-1">
                                    @if ($this->media->model instanceof \App\Models\EventObject)
                                        <h4 class="font-semibold mb-1">{{ $this->media->model->title }}</h4>
                                        <p class="text-sm text-base-content/70">
                                            {{ $this->media->model->type }} • {{ $this->media->model->concept }}
                                        </p>
                                    @elseif ($this->media->model instanceof \App\Models\Block)
                                        <h4 class="font-semibold mb-1">{{ $this->media->model->title }}</h4>
                                        <p class="text-sm text-base-content/70">
                                            {{ $this->media->model->block_type }}
                                        </p>
                                    @elseif ($this->media->model instanceof \App\Models\Event)
                                        <h4 class="font-semibold mb-1">
                                            {{ $this->media->model->actor?->title }} → {{ $this->media->model->target?->title }}
                                        </h4>
                                        <p class="text-sm text-base-content/70">
                                            {{ $this->media->model->action }} • {{ $this->media->model->domain }}
                                        </p>
                                    @endif
                                </div>

                                <div>
                                    @if ($this->media->model instanceof \App\Models\EventObject)
                                        <a href="{{ route('objects.show', $this->media->model->id) }}" wire:navigate class="btn btn-sm btn-primary">
                                            <x-icon name="fas.arrow-right" class="w-4 h-4" />
                                            View Object
                                        </a>
                                    @elseif ($this->media->model instanceof \App\Models\Block)
                                        <a href="{{ route('blocks.show', $this->media->model->id) }}" wire:navigate class="btn btn-sm btn-primary">
                                            <x-icon name="fas.arrow-right" class="w-4 h-4" />
                                            View Block
                                        </a>
                                    @elseif ($this->media->model instanceof \App\Models\Event)
                                        <a href="{{ route('events.show', $this->media->model->id) }}" wire:navigate class="btn btn-sm btn-primary">
                                            <x-icon name="fas.arrow-right" class="w-4 h-4" />
                                            View Event
                                        </a>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </x-card>
                @endif

                {{-- Conversions --}}
                @if ($conversions->isNotEmpty())
                    <x-card class="bg-base-100 shadow">
                        <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                            <x-icon name="fas.image" class="w-5 h-5 text-success" />
                            Image Conversions ({{ $conversions->count() }})
                        </h3>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            @foreach ($conversions as $conversion)
                                <div class="p-3 bg-base-200 rounded-lg">
                                    <div class="aspect-video bg-base-300 rounded mb-2 overflow-hidden">
                                        <img
                                            src="{{ $conversion['url'] }}"
                                            alt="{{ $conversion['name'] }}"
                                            class="w-full h-full object-cover"
                                        />
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm font-medium">{{ ucfirst($conversion['name']) }}</span>
                                        <a href="{{ $conversion['url'] }}" target="_blank" class="btn btn-ghost btn-xs">
                                            <x-icon name="o-arrow-top-right-on-square" class="w-3 h-3" />
                                        </a>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-4">
                            <button wire:click="regenerateConversions" class="btn btn-sm btn-outline">
                                <x-icon name="fas.rotate" class="w-4 h-4" />
                                Regenerate Conversions
                            </button>
                        </div>
                    </x-card>
                @endif
            </div>

            {{-- Right Sidebar Drawer --}}
            <x-drawer
                wire:model="showSidebar"
                right
                title="Media Details"
                with-close-button
                separator
                class="w-11/12 lg:w-1/3"
            >
                <div class="space-y-4">
                    {{-- Details Section --}}
                    <x-collapse wire:model="detailsOpen">
                        <x-slot:heading>
                            <div class="flex items-center gap-2">
                                <x-icon name="fas.circle-info" class="w-5 h-5" />
                                File Information
                            </div>
                        </x-slot:heading>
                        <x-slot:content>
                            <div class="space-y-3 text-sm">
                                <div>
                                    <span class="text-base-content/60">File Name:</span>
                                    <div class="font-mono text-xs break-all mt-1">{{ $this->media->file_name }}</div>
                                </div>
                                <div>
                                    <span class="text-base-content/60">Name:</span>
                                    <div class="mt-1">{{ $this->media->name ?: '—' }}</div>
                                </div>
                                <div>
                                    <span class="text-base-content/60">Collection:</span>
                                    <div class="mt-1">{{ ucfirst(str_replace('_', ' ', $this->media->collection_name ?? 'media')) }}</div>
                                </div>
                                <div>
                                    <span class="text-base-content/60">Size:</span>
                                    <div class="mt-1">{{ $this->media->humanReadableSize }} ({{ number_format($this->media->size) }} bytes)</div>
                                </div>
                                <div>
                                    <span class="text-base-content/60">MIME Type:</span>
                                    <div class="mt-1">{{ $this->media->mime_type }}</div>
                                </div>
                                <div>
                                    <span class="text-base-content/60">Disk:</span>
                                    <div class="mt-1">{{ $this->media->disk }}</div>
                                </div>
                                <div>
                                    <span class="text-base-content/60">UUID:</span>
                                    <div class="font-mono text-xs break-all mt-1">{{ $this->media->uuid }}</div>
                                </div>
                            </div>
                        </x-slot:content>
                    </x-collapse>

                    {{-- Technical Details Section --}}
                    <x-collapse wire:model="technicalOpen">
                        <x-slot:heading>
                            <div class="flex items-center gap-2">
                                <x-icon name="fas.gear" class="w-5 h-5" />
                                Technical Details
                            </div>
                        </x-slot:heading>
                        <x-slot:content>
                            <div class="space-y-3 text-sm">
                                @if ($this->media->getCustomProperty('width') && $this->media->getCustomProperty('height'))
                                    <div>
                                        <span class="text-base-content/60">Dimensions:</span>
                                        <div class="mt-1">{{ $this->media->getCustomProperty('width') }} × {{ $this->media->getCustomProperty('height') }} px</div>
                                    </div>
                                @endif
                                <div>
                                    <span class="text-base-content/60">Path:</span>
                                    <div class="font-mono text-xs break-all mt-1">{{ $this->media->getPath() }}</div>
                                </div>
                                <div>
                                    <span class="text-base-content/60">URL:</span>
                                    <div class="font-mono text-xs break-all mt-1">
                                        <a href="{{ $mediaUrl }}" target="_blank" class="link">
                                            @if ($isS3)
                                                <span class="text-warning">[Signed URL - expires in 60 min]</span>
                                            @else
                                                {{ $this->media->getUrl() }}
                                            @endif
                                        </a>
                                    </div>
                                </div>
                                @if ($this->media->custom_properties && count($this->media->custom_properties) > 0)
                                    <div>
                                        <span class="text-base-content/60">Custom Properties:</span>
                                        <div class="mt-1">
                                            <x-metadata-list :data="$this->media->custom_properties" />
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </x-slot:content>
                    </x-collapse>

                    {{-- Activity Section --}}
                    <x-collapse wire:model="activityOpen">
                        <x-slot:heading>
                            <div class="flex items-center gap-2">
                                <x-icon name="fas.clock" class="w-5 h-5" />
                                Timeline
                            </div>
                        </x-slot:heading>
                        <x-slot:content>
                            <div class="space-y-3 text-sm">
                                <div>
                                    <span class="text-base-content/60">Created:</span>
                                    <div class="mt-1"><x-uk-date :date="$this->media->created_at" :show-time="true" /></div>
                                </div>
                                <div>
                                    <span class="text-base-content/60">Last Updated:</span>
                                    <div class="mt-1"><x-uk-date :date="$this->media->updated_at" :show-time="true" /></div>
                                </div>
                                <div>
                                    <span class="text-base-content/60">Order:</span>
                                    <div class="mt-1">{{ $this->media->order_column }}</div>
                                </div>
                            </div>
                        </x-slot:content>
                    </x-collapse>
                </div>
            </x-drawer>
        </div>
    @else
        <div class="text-center py-12">
            <x-icon name="fas.triangle-exclamation" class="w-16 h-16 text-base-content/70 mx-auto mb-4" />
            <h3 class="text-lg font-medium text-base-content mb-2">Media Not Found</h3>
            <a href="{{ route('media.index') }}" wire:navigate class="btn btn-primary btn-sm mt-4">
                Back to Gallery
            </a>
        </div>
    @endif

    {{-- Edit Modal --}}
    <x-modal wire:model="showEditModal" title="Edit Media" subtitle="Update media details" separator>
        <div class="space-y-4">
            <x-input
                label="Name"
                wire:model="editName"
                placeholder="Enter a friendly name for this media"
            />

            <x-input
                label="File Name"
                wire:model="editFileName"
                placeholder="file.jpg"
                hint="This is the actual file name stored on disk"
            />
        </div>

        <x-slot:actions>
            <x-button label="Cancel" @click="$wire.closeEditModal()" />
            <x-button label="Save Changes" class="btn-primary" wire:click="saveEdit" />
        </x-slot:actions>
    </x-modal>

    {{-- Delete Confirmation Modal --}}
    <x-modal wire:model="showDeleteConfirm" title="Delete Media" separator>
        <div class="space-y-4">
            <p>Are you sure you want to delete this media file?</p>
            <p class="text-error text-sm">This action cannot be undone and will permanently delete the file from storage.</p>

            <div class="p-3 bg-base-200 rounded">
                <p class="text-sm font-mono break-all">{{ $this->media->file_name }}</p>
            </div>
        </div>

        <x-slot:actions>
            <x-button label="Cancel" @click="$wire.closeDeleteConfirm()" />
            <x-button label="Delete Permanently" class="btn-error" wire:click="deleteMedia" />
        </x-slot:actions>
    </x-modal>
</div>
