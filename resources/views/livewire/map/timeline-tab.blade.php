<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
    {{-- Left Column: Timeline --}}
    <div class="lg:col-span-1 space-y-4">
        {{-- Timeline Controls --}}
        <div class="card bg-base-200">
            <div class="card-body p-4">
                <div class="form-control">
                    <label class="label">
                        <span class="label-text">Group By</span>
                    </label>
                    <select wire:model.live="timelineGrouping" class="select select-sm select-bordered">
                        <option value="hour">Hour</option>
                        <option value="day">Day</option>
                        <option value="week">Week</option>
                    </select>
                </div>
                <div class="form-control">
                    <label class="label cursor-pointer">
                        <span class="label-text">Show journey routes</span>
                        <input type="checkbox" class="toggle toggle-sm toggle-primary" wire:model.live="showJourneyRoutes" />
                    </label>
                </div>
            </div>
        </div>

        {{-- Timeline Events --}}
        <div class="space-y-4 max-h-[600px] overflow-y-auto">
            @foreach ($this->timelineData as $date => $dayEvents)
            <div class="card bg-base-200">
                <div class="card-body p-4">
                    <h3 class="font-semibold mb-2">
                        {{ \Carbon\Carbon::parse($date)->format('D, M j, Y') }}
                    </h3>
                    <div class="space-y-2">
                        @foreach ($dayEvents as $index => $event)
                        <div class="flex items-start gap-2 p-2 hover:bg-base-300 rounded cursor-pointer transition-colors"
                             wire:click="$dispatch('highlight-event', { eventId: '{{ $event->id }}' })">
                            <div class="badge badge-sm badge-primary">{{ $event->time->format('H:i') }}</div>
                            <div class="flex-1 text-sm">
                                <div class="font-medium">{{ $event->target->title ?? 'Event' }}</div>
                                <div class="text-xs text-base-content/70">{{ $event->location_address }}</div>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
            @endforeach

            @if (count($this->timelineData) === 0)
            <div class="card bg-base-200">
                <div class="card-body text-center p-8">
                    <x-icon name="o-map-pin" class="w-12 h-12 mx-auto mb-2 text-base-content/30" />
                    <p class="text-sm text-base-content/70">No events with location data in this period</p>
                </div>
            </div>
            @endif
        </div>
    </div>

    {{-- Right Column: Map --}}
    <div class="lg:col-span-2">
        <div class="card bg-base-200" style="height: 600px;">
            <div class="card-body p-0">
                <div wire:ignore style="position: relative; width: 100%; height: 100%;">
                    <div id="timeline-map-{{ $this->getId() }}" style="height: 100%; width: 100%;"></div>
                </div>
            </div>
        </div>
    </div>
</div>

@script
<script>
    const timelineMapId = 'timeline-map-{{ $this->getId() }}';
    let timelineMap = null;
    let timelineMarkers = {};
    let journeyPolylines = [];

    function initTimelineMap() {
        if (timelineMap) {
            timelineMap.remove();
        }

        timelineMap = L.map(timelineMapId).setView([54.5, -2.0], 6);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors',
            maxZoom: 19,
        }).addTo(timelineMap);

        loadTimelineData();
    }

    async function loadTimelineData() {
        const component = Livewire.find('{{ $this->getId() }}');
        if (!component) return;

        // Clear existing markers and polylines
        Object.values(timelineMarkers).forEach(m => m.remove());
        journeyPolylines.forEach(p => p.remove());
        timelineMarkers = {};
        journeyPolylines = [];

        // Get timeline data
        const timelineData = await component.call('getMapData');
        const journeyRoutes = @json($this->journeyRoutes);

        // Add numbered markers for each event
        let markerNumber = 1;
        const bounds = [];

        timelineData.forEach(item => {
            const marker = L.marker([item.latitude, item.longitude], {
                icon: L.divIcon({
                    className: 'custom-number-icon',
                    html: `<div class="rounded-full bg-primary text-primary-content w-8 h-8 flex items-center justify-center font-bold text-sm shadow-lg">${markerNumber}</div>`,
                    iconSize: [32, 32],
                    iconAnchor: [16, 16],
                })
            });

            marker.bindPopup(item.popup_html);
            marker.addTo(timelineMap);
            timelineMarkers[item.id] = marker;
            bounds.push([item.latitude, item.longitude]);
            markerNumber++;
        });

        // Add journey routes
        if (journeyRoutes && journeyRoutes.length > 0) {
            journeyRoutes.forEach(route => {
                const isDashed = route.time_gap_minutes > 120; // Dashed if gap > 2 hours
                const polyline = L.polyline(
                    [[route.from.lat, route.from.lng], [route.to.lat, route.to.lng]],
                    {
                        color: '#6b7280',
                        weight: 2,
                        opacity: 0.5,
                        dashArray: isDashed ? '10, 10' : null,
                    }
                ).addTo(timelineMap);

                journeyPolylines.push(polyline);
            });
        }

        // Fit bounds
        if (bounds.length > 0) {
            timelineMap.fitBounds(bounds, { padding: [50, 50] });
        }
    }

    // Initialize map
    setTimeout(() => initTimelineMap(), 500);

    // Listen for filter updates
    window.addEventListener('map-filters-updated', () => loadTimelineData());

    // Listen for event highlighting
    window.addEventListener('highlight-event', (e) => {
        const eventId = e.detail.eventId;
        const marker = timelineMarkers[eventId];
        if (marker) {
            timelineMap.setView(marker.getLatLng(), 15);
            marker.openPopup();
        }
    });
</script>
@endscript
