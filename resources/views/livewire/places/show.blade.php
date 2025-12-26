<div>
    <x-header
        :title="$place->title"
        subtitle="Place"
        separator>
        <x-slot:actions>
            {{-- Favorite Toggle --}}
            <button
                wire:click="toggleFavorite"
                class="btn btn-ghost btn-sm">
                @if ($place->is_favorite)
                    <x-icon name="fas.star" class="w-5 h-5 text-warning" />
                @else
                    <x-icon name="o-star" class="w-5 h-5" />
                @endif
            </button>

            {{-- Edit Button --}}
            @if (!$editing)
            <button
                wire:click="startEditing"
                class="btn btn-sm btn-primary">
                <x-icon name="o-pencil" class="w-4 h-4" />
                Edit
            </button>
            @endif

            {{-- Delete Button --}}
            <button
                wire:click="deletePlace"
                wire:confirm="Are you sure you want to delete this place? All linked events will be unlinked."
                class="btn btn-sm btn-error">
                <x-icon name="o-trash" class="w-4 h-4" />
                Delete
            </button>
        </x-slot:actions>
    </x-header>

    <div class="p-4 space-y-4">
        {{-- Success Message --}}
        @if (session()->has('message'))
        <div class="alert alert-success">
            <x-icon name="o-check-circle" class="w-5 h-5" />
            <span>{{ session('message') }}</span>
        </div>
        @endif

        {{-- Edit Form --}}
        @if ($editing)
        <div class="card bg-base-200">
            <div class="card-body">
                <h3 class="card-title text-lg mb-4">Edit Place</h3>

                <div class="space-y-4">
                    {{-- Title --}}
                    <div>
                        <label class="label">
                            <span class="label-text">Title</span>
                        </label>
                        <input
                            type="text"
                            class="input input-bordered w-full"
                            wire:model="editTitle" />
                        @error('editTitle') <span class="text-error text-sm mt-1">{{ $message }}</span> @enderror
                    </div>

                    {{-- Category --}}
                    <div>
                        <label class="label">
                            <span class="label-text">Category</span>
                        </label>
                        <select
                            class="select select-bordered w-full"
                            wire:model="editCategory">
                            <option value="">No Category</option>
                            <option value="home">Home</option>
                            <option value="work">Work</option>
                            <option value="gym">Gym</option>
                            <option value="cafe">Cafe</option>
                            <option value="restaurant">Restaurant</option>
                            <option value="bar">Bar</option>
                            <option value="shop">Shop</option>
                            <option value="office">Office</option>
                            <option value="transport">Transport</option>
                            <option value="health">Health</option>
                            <option value="education">Education</option>
                            <option value="entertainment">Entertainment</option>
                            <option value="hotel">Hotel</option>
                        </select>
                        @error('editCategory') <span class="text-error text-sm mt-1">{{ $message }}</span> @enderror
                    </div>

                    {{-- Detection Radius --}}
                    <div>
                        <label class="label">
                            <span class="label-text">Detection Radius (meters)</span>
                            <span class="label-text-alt">{{ $editDetectionRadius }}m</span>
                        </label>
                        <input
                            type="range"
                            min="10"
                            max="500"
                            step="10"
                            class="range range-sm"
                            wire:model.live="editDetectionRadius" />
                        <div class="w-full flex justify-between text-xs px-2 mt-1">
                            <span>10m</span>
                            <span>250m</span>
                            <span>500m</span>
                        </div>
                        @error('editDetectionRadius') <span class="text-error text-sm mt-1">{{ $message }}</span> @enderror
                    </div>

                    {{-- Favorite Toggle --}}
                    <div>
                        <label class="label cursor-pointer justify-start gap-4">
                            <input
                                type="checkbox"
                                class="toggle toggle-primary"
                                wire:model="editIsFavorite" />
                            <span class="label-text">Mark as favorite</span>
                        </label>
                    </div>

                    {{-- Actions --}}
                    <div class="flex gap-2 justify-end">
                        <button
                            wire:click="cancelEditing"
                            class="btn btn-ghost">
                            Cancel
                        </button>
                        <button
                            wire:click="savePlace"
                            class="btn btn-primary">
                            <x-icon name="o-check" class="w-4 h-4" />
                            Save Changes
                        </button>
                    </div>
                </div>
            </div>
        </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            {{-- Main Column --}}
            <div class="lg:col-span-2 space-y-4">
                {{-- Location Card --}}
                <div class="card bg-base-200">
                    <div class="card-body">
                        <h3 class="card-title text-base mb-4">
                            <x-icon name="o-map-pin" class="w-5 h-5" />
                            Location
                        </h3>

                        @if ($place->location_address)
                        <p class="text-sm mb-4">{{ $place->location_address }}</p>
                        @endif

                        @if ($place->latitude && $place->longitude)
                        {{-- Map --}}
                        <div wire:ignore class="h-64 rounded-lg overflow-hidden mb-4">
                            <div id="place-map-{{ $place->id }}" class="h-full w-full"></div>
                        </div>

                        {{-- Coordinates --}}
                        <div class="text-xs text-base-content/70 space-y-1">
                            <div>Latitude: {{ $place->latitude }}</div>
                            <div>Longitude: {{ $place->longitude }}</div>
                            <div>Detection Radius: {{ $place->detection_radius }}m</div>
                        </div>

                        {{-- External Map Link --}}
                        <div class="mt-4">
                            <a
                                href="https://www.google.com/maps/search/?api=1&query={{ $place->latitude }},{{ $place->longitude }}"
                                target="_blank"
                                class="btn btn-sm btn-ghost">
                                <x-icon name="o-arrow-top-right-on-square" class="w-4 h-4" />
                                Open in Google Maps
                            </a>
                        </div>
                        @else
                        <div class="text-sm text-base-content/70">No location data available</div>
                        @endif
                    </div>
                </div>

                {{-- Events at this Place --}}
                <div class="card bg-base-200">
                    <div class="card-body">
                        <h3 class="card-title text-base mb-4">
                            <x-icon name="o-calendar" class="w-5 h-5" />
                            Events at this Place
                            <span class="badge badge-sm">{{ $this->stats['linked_events'] }}</span>
                        </h3>

                        @if ($this->eventsAtPlace->count() > 0)
                        <div class="space-y-2">
                            @foreach ($this->eventsAtPlace as $event)
                            <div class="flex items-center justify-between p-3 bg-base-100 rounded-lg">
                                <div class="flex-1">
                                    <a href="{{ route('events.show', $event) }}" class="font-medium hover:underline">
                                        {{ $event->action_display }}
                                    </a>
                                    <div class="text-xs text-base-content/70 mt-1">
                                        {{ $event->time->format('M j, Y g:i A') }}
                                    </div>
                                </div>
                                <button
                                    wire:click="unlinkEvent('{{ $event->id }}')"
                                    wire:confirm="Unlink this event from this place?"
                                    class="btn btn-ghost btn-xs">
                                    <x-icon name="o-x-mark" class="w-4 h-4" />
                                </button>
                            </div>
                            @endforeach
                        </div>

                        {{-- Pagination --}}
                        <div class="mt-4">
                            {{ $this->eventsAtPlace->links() }}
                        </div>
                        @else
                        <div class="text-sm text-base-content/70 text-center py-8">
                            No events linked to this place yet
                        </div>
                        @endif
                    </div>
                </div>

                {{-- Nearby Events (not yet linked) --}}
                @if ($this->nearbyEvents->count() > 0)
                <div class="card bg-base-200">
                    <div class="card-body">
                        <h3 class="card-title text-base mb-4">
                            <x-icon name="o-map" class="w-5 h-5" />
                            Nearby Events (within {{ $place->detection_radius }}m)
                            <span class="badge badge-sm">{{ $this->nearbyEvents->count() }}</span>
                        </h3>

                        <div class="space-y-2">
                            @foreach ($this->nearbyEvents as $event)
                            <div class="flex items-center justify-between p-3 bg-base-100 rounded-lg">
                                <div class="flex-1">
                                    <a href="{{ route('events.show', $event) }}" class="font-medium hover:underline">
                                        {{ $event->action_display }}
                                    </a>
                                    <div class="text-xs text-base-content/70 mt-1">
                                        {{ $event->time->format('M j, Y g:i A') }}
                                    </div>
                                </div>
                                <button
                                    wire:click="linkNearbyEvent('{{ $event->id }}')"
                                    class="btn btn-primary btn-xs">
                                    <x-icon name="o-link" class="w-4 h-4" />
                                    Link
                                </button>
                            </div>
                            @endforeach
                        </div>
                    </div>
                </div>
                @endif
            </div>

            {{-- Sidebar --}}
            <div class="space-y-4">
                {{-- Stats Card --}}
                <div class="card bg-base-200">
                    <div class="card-body">
                        <h3 class="card-title text-base mb-4">Statistics</h3>

                        <div class="stats stats-vertical shadow bg-base-100">
                            <div class="stat p-4">
                                <div class="stat-title text-xs">Visit Count</div>
                                <div class="stat-value text-2xl">{{ $this->stats['visit_count'] }}</div>
                            </div>

                            <div class="stat p-4">
                                <div class="stat-title text-xs">Linked Events</div>
                                <div class="stat-value text-2xl">{{ $this->stats['linked_events'] }}</div>
                            </div>

                            @if ($this->stats['first_visit_at'])
                            <div class="stat p-4">
                                <div class="stat-title text-xs">First Visit</div>
                                <div class="stat-desc">{{ \Carbon\Carbon::parse($this->stats['first_visit_at'])->format('M j, Y') }}</div>
                            </div>
                            @endif

                            @if ($this->stats['last_visit_at'])
                            <div class="stat p-4">
                                <div class="stat-title text-xs">Last Visit</div>
                                <div class="stat-desc">{{ \Carbon\Carbon::parse($this->stats['last_visit_at'])->diffForHumans() }}</div>
                            </div>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Category & Tags --}}
                <div class="card bg-base-200">
                    <div class="card-body">
                        <h3 class="card-title text-base mb-4">Details</h3>

                        @if ($place->category)
                        <div class="mb-3">
                            <div class="text-xs text-base-content/70 mb-1">Category</div>
                            <div class="badge badge-outline">{{ ucfirst($place->category) }}</div>
                        </div>
                        @endif

                        @if ($place->tags->count() > 0)
                        <div>
                            <div class="text-xs text-base-content/70 mb-2">Tags</div>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($place->tags as $tag)
                                <div class="badge badge-sm">{{ $tag->name }}</div>
                                @endforeach
                            </div>
                        </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    @script
    <script>
        const placeId = '{{ $place->id }}';
        const mapId = 'place-map-' + placeId;

        // Wait for Leaflet to be available
        if (typeof L !== 'undefined') {
            const lat = {{ $place->latitude ?? 'null' }};
            const lng = {{ $place->longitude ?? 'null' }};
            const radius = {{ $place->detection_radius ?? 50 }};

            if (lat && lng) {
                const map = L.map(mapId).setView([lat, lng], 16);

                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                }).addTo(map);

                // Add marker for the place
                L.marker([lat, lng]).addTo(map)
                    .bindPopup('<b>{{ addslashes($place->title) }}</b>');

                // Add circle showing detection radius
                L.circle([lat, lng], {
                    color: 'blue',
                    fillColor: '#3b82f6',
                    fillOpacity: 0.1,
                    radius: radius
                }).addTo(map);
            }
        }
    </script>
    @endscript
</div>
