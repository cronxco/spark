<div>
    @if ($latitude && $longitude)
        <!-- Display Mode: Show location with option to edit or view on map -->
        <div class="space-y-3">
            <div class="flex items-start gap-3 p-3 rounded-lg bg-success/10 border border-success/20">
                <x-icon name="fas.location-dot" class="w-5 h-5 text-success flex-shrink-0 mt-0.5" />
                <div class="flex-1 min-w-0">
                    @if ($locationAddress)
                        <div class="font-medium text-base-content text-sm">{{ $locationAddress }}</div>
                    @endif
                    <div class="text-xs text-base-content/60 mt-1 font-mono">
                        {{ number_format($latitude, 6) }}, {{ number_format($longitude, 6) }}
                    </div>
                    @if ($locationSource)
                        <div class="text-xs text-base-content/50 mt-1">
                            Source: {{ ucfirst($locationSource) }}
                        </div>
                    @endif
                </div>
            </div>

            <div class="flex gap-2">
                <a href="https://www.google.com/maps?q={{ $latitude }},{{ $longitude }}"
                   target="_blank"
                   class="btn btn-sm btn-outline gap-2 flex-1">
                    <x-icon name="fas.map" class="w-4 h-4" />
                    View on Map
                </a>
                <button type="button"
                        wire:click="clearLocation"
                        class="btn btn-sm btn-ghost btn-error gap-2">
                    <x-icon name="fas.trash" class="w-4 h-4" />
                    Clear
                </button>
            </div>
        </div>
    @else
        <!-- Edit Mode: Add location -->
        <div class="space-y-3">
            <div class="alert alert-info">
                <x-icon name="fas.info-circle" class="w-5 h-5" />
                <span class="text-sm">No location set. Add an address or coordinates to show this item on the map.</span>
            </div>

            <x-form wire:submit="save">
                <!-- Address input with geocode button -->
                <div class="form-control">
                    <label class="label">
                        <span class="label-text">Address</span>
                    </label>
                    <div class="flex gap-2">
                        <x-input
                            wire:model="locationAddress"
                            placeholder="e.g., 10 Downing Street, London"
                            class="flex-1"
                            hint="Enter an address and click Geocode to find coordinates"
                        />
                        <x-button
                            type="button"
                            wire:click="geocode"
                            class="btn-outline"
                            :disabled="$isGeocoding"
                            spinner="geocode">
                            @if ($isGeocoding)
                                <span class="loading loading-spinner loading-sm"></span>
                            @else
                                <x-icon name="fas.search-location" class="w-4 h-4" />
                            @endif
                            Geocode
                        </x-button>
                    </div>
                </div>

                @if ($geocodeError)
                    <div class="alert alert-warning">
                        <x-icon name="fas.exclamation-triangle" class="w-5 h-5" />
                        <span class="text-sm">{{ $geocodeError }}</span>
                    </div>
                @endif

                <!-- Manual coordinates toggle -->
                <div>
                    <button
                        type="button"
                        wire:click="toggleManualCoordinates"
                        class="text-sm text-base-content/70 hover:text-base-content flex items-center gap-2">
                        <x-icon name="{{ $showManualCoordinates ? 'fas.chevron-down' : 'fas.chevron-right' }}" class="w-3 h-3" />
                        Or enter coordinates manually
                    </button>
                </div>

                @if ($showManualCoordinates)
                    <div class="grid grid-cols-2 gap-4 pl-5">
                        <x-input
                            label="Latitude"
                            wire:model="latitude"
                            type="number"
                            step="0.000001"
                            placeholder="51.503364"
                            hint="-90 to 90"
                        />
                        <x-input
                            label="Longitude"
                            wire:model="longitude"
                            type="number"
                            step="0.000001"
                            placeholder="-0.127625"
                            hint="-180 to 180"
                        />
                    </div>
                @endif

                <x-slot:actions>
                    <x-button
                        label="Save Location"
                        class="btn-primary"
                        type="submit"
                        spinner="save"
                        :disabled="!$latitude || !$longitude"
                    />
                </x-slot:actions>
            </x-form>
        </div>
    @endif
</div>
