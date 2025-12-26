<div class="card bg-base-200 shadow">
    <div class="card-body">
        <h3 class="card-title">Workout Route</h3>

        @if (!empty($this->routePoints))
        {{-- Route Map --}}
        <div wire:ignore style="position: relative; width: 100%; height: 400px;">
            <div id="route-map-{{ $this->getId() }}" style="height: 100%; width: 100%;"></div>
        </div>

        {{-- Route Stats --}}
        <div class="stats stats-vertical lg:stats-horizontal shadow mt-4">
            <div class="stat">
                <div class="stat-title">Total Points</div>
                <div class="stat-value text-2xl">{{ $this->routeSummary['total_points'] ?? 0 }}</div>
            </div>
            @if (isset($event->event_metadata['distance']))
            <div class="stat">
                <div class="stat-title">Distance</div>
                <div class="stat-value text-2xl">
                    {{ number_format($event->event_metadata['distance'], 2) }}
                    <span class="text-sm">{{ $event->event_metadata['distance_unit'] ?? 'km' }}</span>
                </div>
            </div>
            @endif
            @if (isset($event->event_metadata['duration_seconds']))
            <div class="stat">
                <div class="stat-title">Duration</div>
                <div class="stat-value text-2xl">{{ format_duration($event->event_metadata['duration_seconds']) }}</div>
            </div>
            @endif
        </div>

        @script
        <script>
            const routePoints = @json($this->routePoints);
            const mapId = 'route-map-{{ $this->getId() }}';

            if (routePoints && routePoints.length > 0) {
                const map = L.map(mapId);

                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; OpenStreetMap contributors',
                    maxZoom: 19,
                }).addTo(map);

                // Create polyline from route points
                const latLngs = routePoints.map(p => [p.lat, p.lng]);
                const polyline = L.polyline(latLngs, {
                    color: 'blue',
                    weight: 3,
                    opacity: 0.7
                }).addTo(map);

                // Add start marker (green)
                if (routePoints[0]) {
                    L.marker([routePoints[0].lat, routePoints[0].lng], {
                        icon: L.icon({
                            iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-green.png',
                            shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/images/marker-shadow.png',
                            iconSize: [25, 41],
                            iconAnchor: [12, 41],
                            popupAnchor: [1, -34],
                            shadowSize: [41, 41]
                        })
                    }).addTo(map).bindPopup('Start');
                }

                // Add end marker (red)
                if (routePoints[routePoints.length - 1]) {
                    const endPoint = routePoints[routePoints.length - 1];
                    L.marker([endPoint.lat, endPoint.lng], {
                        icon: L.icon({
                            iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-red.png',
                            shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/images/marker-shadow.png',
                            iconSize: [25, 41],
                            iconAnchor: [12, 41],
                            popupAnchor: [1, -34],
                            shadowSize: [41, 41]
                        })
                    }).addTo(map).bindPopup('End');
                }

                // Fit map to route bounds
                map.fitBounds(polyline.getBounds().pad(0.1));
            }
        </script>
        @endscript
        @else
        <div class="text-center text-base-content/70 py-8">
            No route data available for this workout
        </div>
        @endif
    </div>
</div>
