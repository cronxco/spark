<div>
    <x-header title="Mapss" subtitle="Events, objects, and places with location data" separator />

    <x-tabs wire:model="viewMode" selected="map">
        {{-- Map Tab --}}
        <x-tab name="map" label="Maps" icon="o-map">
            <div class="p-4 space-y-4">
                {{-- Filters --}}
                <div class="card bg-base-200">
                    <div class="card-body p-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                            {{-- View Type Toggle --}}
                            <div>
                                <label class="label">
                                    <span class="label-text">View</span>
                                </label>
                                <div class="join w-full">
                                    <button
                                        wire:click="$set('viewType', 'events')"
                                        class="btn btn-sm join-item flex-1 {{ $viewType === 'events' ? 'btn-primary' : 'btn-ghost' }}">
                                        Events
                                    </button>
                                    <button
                                        wire:click="$set('viewType', 'objects')"
                                        class="btn btn-sm join-item flex-1 {{ $viewType === 'objects' ? 'btn-primary' : 'btn-ghost' }}">
                                        Objects
                                    </button>
                                </div>
                            </div>

                            {{-- Service Filter (Events only) --}}
                            @if ($viewType === 'events' && !empty($this->availableServices))
                            <div>
                                <label class="label">
                                    <span class="label-text">Services</span>
                                </label>
                                <div class="dropdown dropdown-bottom w-full">
                                    <button tabindex="0" class="btn btn-sm btn-ghost w-full justify-between">
                                        <span class="truncate">
                                            @if (empty($selectedServices))
                                            All Services
                                            @else
                                            {{ count($selectedServices) }} selected
                                            @endif
                                        </span>
                                        <x-icon name="fas.chevron-down" class="w-3 h-3" />
                                    </button>
                                    <div tabindex="0" class="dropdown-content z-10 menu p-2 shadow bg-base-100 rounded-box w-full max-h-60 overflow-y-auto">
                                        @foreach ($this->availableServices as $service)
                                        <label class="label cursor-pointer">
                                            <span class="label-text flex items-center gap-2">
                                                <x-icon :name="$service['icon']" class="w-4 h-4" />
                                                {{ $service['label'] }}
                                            </span>
                                            <input
                                                type="checkbox"
                                                class="checkbox checkbox-sm"
                                                wire:model.live="selectedServices"
                                                value="{{ $service['value'] }}" />
                                        </label>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                            @endif

                            {{-- Date Range --}}
                            <div>
                                <label class="label">
                                    <span class="label-text">Start Date</span>
                                </label>
                                <input
                                    type="date"
                                    class="input input-sm input-bordered w-full"
                                    wire:model.live="startDate" />
                            </div>

                            <div>
                                <label class="label">
                                    <span class="label-text">End Date</span>
                                </label>
                                <input
                                    type="date"
                                    class="input input-sm input-bordered w-full"
                                    wire:model.live="endDate" />
                            </div>
                        </div>

                        {{-- Filter Actions --}}
                        <div class="flex items-center justify-between pt-2">
                            <div class="text-sm text-base-content/70">
                                Showing {{ $viewType === 'events' ? count($this->events) : count($this->objects) }} locations
                            </div>
                            <button
                                wire:click="clearFilters"
                                class="btn btn-sm btn-ghost">
                                Clear Filters
                            </button>
                        </div>
                    </div>
                </div>

                {{-- Map Container --}}
                <div class="card bg-base-200 relative" style="height: 600px;">
                    <div class="card-body p-0" style="height: 600px;">
                        <div wire:ignore style="position: relative; width: 100%; height: 100%;">
                            <div id="spark-map-{{ $this->getId() }}" style="height: 100%; width: 100%;"></div>
                        </div>

                        {{-- No data message overlay --}}
                        <div id="no-data-overlay-{{ $this->getId() }}" class="hidden absolute inset-0 flex items-center justify-center bg-base-200" style="z-index: 1000;">
                            <div class="text-center p-8">
                                <x-icon name="fas.map-location-dot" class="w-16 h-16 mx-auto mb-4 text-base-content/30" />
                                <h3 class="text-lg font-semibold mb-2">No locations found</h3>
                                <p class="text-sm text-base-content/70">
                                    No events or objects with location data match your current filters.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            @script
            <script>
                const componentId = '{{ $this->getId() }}';
                const mapElementId = 'spark-map-' + componentId;
                const overlayElementId = 'no-data-overlay-' + componentId;

                console.log('Map script loaded for component:', componentId);
                console.log('Map element ID:', mapElementId);

                let map = null;
                let markers = [];
                let markerCluster = null;

                function initMap() {
                    if (map) {
                        console.log('Map already initialized');
                        return;
                    }

                    const mapElement = document.getElementById(mapElementId);
                    if (!mapElement) {
                        console.error('Map element not found:', mapElementId);
                        return;
                    }

                    // Check if map element is visible and in the correct position
                    const rect = mapElement.getBoundingClientRect();
                    console.log('Map element position:', rect);

                    if (rect.height === 0 || rect.width === 0) {
                        console.error('Map element has no size');
                        return;
                    }

                    // Check if map was already initialized on this element
                    if (mapElement._leaflet_id) {
                        console.log('Map already exists on this element, removing...');
                        mapElement._leaflet_id = undefined;
                    }

                    console.log('Initializing Leaflet map on element:', mapElementId);

                    // Check if Leaflet is loaded
                    if (typeof L === 'undefined') {
                        console.error('Leaflet library not loaded');
                        return;
                    }

                    // Initialize map centered on UK
                    map = L.map(mapElementId, {
                        preferCanvas: true
                    }).setView([54.5, -2.0], 6);
                    console.log('Map created:', map);
                    console.log('Map container size:', map.getSize());

                    // Add OpenStreetMap tiles
                    const tileLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                        maxZoom: 19,
                    }).addTo(map);

                    tileLayer.on('loading', () => console.log('Tiles loading...'));
                    tileLayer.on('load', () => console.log('Tiles loaded'));
                    tileLayer.on('tileerror', (e) => console.error('Tile error:', e));

                    // Initialize marker cluster group
                    markerCluster = L.markerClusterGroup({
                        maxClusterRadius: 50,
                        spiderfyOnMaxZoom: true,
                        showCoverageOnHover: false,
                        zoomToBoundsOnClick: true
                    });

                    map.addLayer(markerCluster);

                    // Store globally for debugging
                    window.sparkMap = map;
                    window.sparkMarkerCluster = markerCluster;

                    // Force map to recalculate size
                    setTimeout(() => {
                        map.invalidateSize();
                        loadMapData();
                    }, 100);
                }

                async function loadMapData() {
                    console.log('Loading map data...');
                    try {
                        const component = Livewire.find(componentId);
                        if (component) {
                            console.log('Found Livewire component, calling getMapData...');
                            const response = await component.call('getMapData');
                            console.log('Received map data:', response);
                            console.log('Map data type:', typeof response);
                            console.log('Map data is array:', Array.isArray(response));
                            console.log('Map data length:', response ? response.length : 'null');
                            updateMarkers(response);
                        } else {
                            console.error('Livewire component not found');
                        }
                    } catch (error) {
                        console.error('Error loading map data:', error);
                    }
                }

                function updateMarkers(data) {
                    console.log('updateMarkers called with:', data);
                    console.log('markerCluster exists:', !!markerCluster);
                    console.log('map exists:', !!map);

                    if (!markerCluster) {
                        console.error('markerCluster not initialized');
                        return;
                    }

                    if (!map) {
                        console.error('map not initialized');
                        return;
                    }

                    // Clear existing markers
                    markerCluster.clearLayers();
                    markers = [];

                    const noDataOverlay = document.getElementById(overlayElementId);

                    if (!data || data.length === 0) {
                        console.log('No data to display');
                        // Show no data message
                        if (noDataOverlay) {
                            noDataOverlay.classList.remove('hidden');
                        }
                        return;
                    }

                    console.log(`Adding ${data.length} markers to map`);

                    // Hide no data message
                    if (noDataOverlay) {
                        noDataOverlay.classList.add('hidden');
                    }

                    // Add markers for each location
                    data.forEach((item, index) => {
                        console.log(`Adding marker ${index + 1}:`, item.title, item.latitude, item.longitude);
                        const marker = L.marker([item.latitude, item.longitude]);

                        // Use the pre-rendered popup HTML from the server
                        marker.bindPopup(item.popup_html, {
                            maxWidth: 400,
                            minWidth: 300,
                            className: 'spark-map-popup'
                        });

                        markers.push(marker);
                        markerCluster.addLayer(marker);
                    });

                    console.log(`Total markers added: ${markers.length}`);

                    // Fit bounds to show all markers
                    if (markers.length > 0) {
                        const group = L.featureGroup(markers);
                        const bounds = group.getBounds().pad(0.1);
                        console.log('Fitting map to bounds:', bounds);
                        map.fitBounds(bounds);
                    }
                }

                // Initialize map immediately
                setTimeout(() => {
                    console.log('Initializing map...');
                    initMap();
                }, 500);

                // Listen for filter changes from the component
                window.addEventListener('map-filters-updated', () => {
                    console.log('Filters updated, reloading map data...');
                    loadMapData();
                });
            </script>
            @endscript
        </x-tab>

        {{-- Places Tab --}}
        <x-tab name="places" label="Places" icon="o-map-pin">
            <div class="p-4">
                @include('livewire.map.places-tab')
            </div>
        </x-tab>

        {{-- Timeline Tab --}}
        <x-tab name="timeline" label="Timeline" icon="o-clock">
            <div class="p-4">
                @include('livewire.map.timeline-tab')
            </div>
        </x-tab>
    </x-tabs>
</div>