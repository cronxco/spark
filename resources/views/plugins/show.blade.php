<x-layouts.app>
    <x-slot:title>{{ $pluginClass::getDisplayName() }}</x-slot:title>

    <div class="space-y-4 lg:space-y-6">
        <!-- Header -->
        <x-header
            title="{{ $pluginClass::getDisplayName() }}"
            subtitle="{{ $pluginClass::getDescription() }}"
            separator>
            <x-slot:actions>
                <!-- Desktop: Full buttons -->
                <div class="hidden sm:flex gap-2">
                    @php
                        $serviceType = $pluginClass::getServiceType();
                        $identifier = $pluginClass::getIdentifier();
                    @endphp
                    @if ($serviceType === 'oauth')
                        @if ($identifier === 'gocardless')
                            <form method="POST" action="{{ route('integrations.initialize', ['service' => $identifier]) }}">
                                @csrf
                                <button type="submit" class="btn btn-primary">
                                    <x-icon name="o-plus" class="w-4 h-4" />
                                    Add Instance
                                </button>
                            </form>
                        @else
                            <a href="{{ route('integrations.oauth', $identifier) }}" class="btn btn-primary">
                                <x-icon name="o-plus" class="w-4 h-4" />
                                Add Instance
                            </a>
                        @endif
                    @else
                        <form method="POST" action="{{ route('integrations.initialize', ['service' => $identifier]) }}">
                            @csrf
                            <button type="submit" class="btn btn-primary">
                                <x-icon name="o-plus" class="w-4 h-4" />
                                Add Instance
                            </button>
                        </form>
                    @endif
                    <a href="{{ route('integrations.index') }}" class="btn btn-outline">
                        <x-icon name="o-arrow-left" class="w-4 h-4" />
                        Back
                    </a>
                </div>

                <!-- Mobile: Dropdown -->
                <div class="sm:hidden">
                    <x-dropdown>
                        <x-slot:trigger>
                            <x-button class="btn-ghost btn-sm">
                                <x-icon name="o-ellipsis-vertical" class="w-5 h-5" />
                            </x-button>
                        </x-slot:trigger>
                        <x-menu-item title="Add Instance" icon="o-plus" link="{{ route('integrations.oauth', $pluginClass::getIdentifier()) }}" />
                        <x-menu-item title="Back to Integrations" icon="o-arrow-left" link="{{ route('integrations.index') }}" />
                    </x-dropdown>
                </div>
            </x-slot:actions>
        </x-header>

        <!-- Hero Plugin Info Card -->
        <x-card>
            <div class="flex flex-col sm:flex-row items-start gap-4 lg:gap-6">
                <!-- Large plugin icon -->
                <div class="flex-shrink-0 self-center sm:self-start">
                    <div class="w-12 h-12 sm:w-16 sm:h-16 rounded-full bg-primary/10 flex items-center justify-center">
                        <x-icon name="{{ $pluginClass::getIcon() }}" class="w-6 h-6 sm:w-8 sm:h-8 text-primary" />
                    </div>
                </div>

                <!-- Main content -->
                <div class="flex-1">
                    <div class="mb-4 text-center sm:text-left">
                        <h2 class="text-xl sm:text-2xl lg:text-3xl font-bold text-base-content mb-2">
                            {{ $pluginClass::getDisplayName() }}
                        </h2>
                        <p class="text-base-content/70">{{ $pluginClass::getDescription() }}</p>
                    </div>

                    <!-- Key metadata -->
                    <div class="flex flex-wrap items-center justify-center sm:justify-start gap-4 text-sm text-base-content/70">
                        <div class="flex items-center gap-2">
                            <x-icon name="o-cube-transparent" class="w-4 h-4" />
                            <span>{{ ucfirst($pluginClass::getServiceType()) }}</span>
                        </div>
                        <span class="hidden sm:inline">·</span>
                        <div class="flex items-center gap-2">
                            <x-icon name="o-tag" class="w-4 h-4" />
                            <span>{{ ucfirst($pluginClass::getDomain()) }}</span>
                        </div>
                        @if ($group)
                            <span class="hidden sm:inline">·</span>
                            <div class="flex items-center gap-2">
                                <x-icon name="o-check-circle" class="w-4 h-4 text-success" />
                                <span class="text-success font-medium">{{ $group->integrations->count() }} active {{ Str::plural('instance', $group->integrations->count()) }}</span>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </x-card>

        <!-- Connected Instances (if any) -->
        @if ($group && $group->integrations->count() > 0)
        <x-card class="bg-base-200/50 border-2 border-info/10">
            <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                <x-icon name="o-link" class="w-5 h-5 text-info" />
                Your Instances ({{ $group->integrations->count() }})
            </h3>
            <div class="space-y-3">
                @foreach ($group->integrations as $integration)
                <a href="{{ route('integrations.details', $integration->id) }}"
                   class="block border-2 border-info/30 bg-base-100/80 rounded-lg p-3 hover:bg-base-50 transition-colors">
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-3 flex-1 min-w-0">
                            <div class="w-8 h-8 rounded-full bg-info/10 flex items-center justify-center flex-shrink-0">
                                <x-icon name="{{ $pluginClass::getIcon() }}" class="w-4 h-4 text-info" />
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="font-medium truncate">{{ $integration->name ?: $integration->service }}</div>
                                <div class="text-sm text-base-content/70">
                                    {{ $integration->instance_type }}
                                    @if ($integration->last_successful_update_at)
                                        · Updated {{ $integration->last_successful_update_at->diffForHumans() }}
                                    @endif
                                </div>
                            </div>
                        </div>
                        <x-icon name="o-chevron-right" class="w-4 h-4 text-base-content/40 flex-shrink-0" />
                    </div>
                </a>
                @endforeach
            </div>
        </x-card>
        @endif

        <!-- Action Types Section -->
        @if (count($pluginClass::getActionTypes()) > 0)
        <x-card class="bg-base-200 shadow">
            <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                <x-icon name="o-bolt" class="w-5 h-5 text-primary" />
                Action Types
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                @foreach ($pluginClass::getActionTypes() as $key => $action)
                    @php
                        $count = \App\Models\Event::where('service', $service)
                            ->where('action', $key)
                            ->whereHas('integration', function($query) {
                                $query->where('user_id', auth()->id());
                            })
                            ->count();

                        $newest = \App\Models\Event::where('service', $service)
                            ->where('action', $key)
                            ->whereHas('integration', function($query) {
                                $query->where('user_id', auth()->id());
                            })
                            ->orderBy('created_at', 'desc')
                            ->first();
                    @endphp

                    <div class="border border-base-200 bg-base-100 rounded-lg p-3 hover:bg-base-50 transition-colors">
                        <div class="flex items-start gap-3 mb-2">
                            <div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center flex-shrink-0 mt-1">
                                <x-icon name="{{ $action['icon'] }}" class="w-4 h-4 text-primary" />
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="font-medium text-base">{{ $action['display_name'] }}</div>
                                <p class="text-sm text-base-content/70 mb-2">{{ $action['description'] }}</p>
                                <div class="flex items-center gap-2">
                                    <span class="text-lg font-bold text-primary">{{ $count }}</span>
                                    @if ($newest)
                                        <span class="text-xs text-base-content/70">{{ $newest->created_at->diffForHumans() }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-card>
        @endif

        <!-- Object Types Section -->
        @if (count($pluginClass::getObjectTypes()) > 0)
        <x-card class="bg-base-200 shadow">
            <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                <x-icon name="o-squares-2x2" class="w-5 h-5 text-info" />
                Object Types
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                @foreach ($pluginClass::getObjectTypes() as $key => $object)
                    @php
                        $count = \App\Models\EventObject::where('type', $key)
                            ->where(function($query) use ($service) {
                                $query->whereHas('actorEvents', function($q) use ($service) {
                                    $q->where('service', $service)
                                      ->whereHas('integration', function($iq) {
                                          $iq->where('user_id', auth()->id());
                                      });
                                })->orWhereHas('targetEvents', function($q) use ($service) {
                                    $q->where('service', $service)
                                      ->whereHas('integration', function($iq) {
                                          $iq->where('user_id', auth()->id());
                                      });
                                });
                            })
                            ->count();

                        $newest = \App\Models\EventObject::where('type', $key)
                            ->where(function($query) use ($service) {
                                $query->whereHas('actorEvents', function($q) use ($service) {
                                    $q->where('service', $service)
                                      ->whereHas('integration', function($iq) {
                                          $iq->where('user_id', auth()->id());
                                      });
                                })->orWhereHas('targetEvents', function($q) use ($service) {
                                    $q->where('service', $service)
                                      ->whereHas('integration', function($iq) {
                                          $iq->where('user_id', auth()->id());
                                      });
                                });
                            })
                            ->orderBy('created_at', 'desc')
                            ->first();
                    @endphp

                    <div class="border border-base-200 bg-base-100 rounded-lg p-3 hover:bg-base-50 transition-colors">
                        <div class="flex items-start gap-3 mb-2">
                            <div class="w-8 h-8 rounded-full bg-info/10 flex items-center justify-center flex-shrink-0 mt-1">
                                <x-icon name="{{ $object['icon'] }}" class="w-4 h-4 text-info" />
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="font-medium text-base">{{ $object['display_name'] }}</div>
                                <p class="text-sm text-base-content/70 mb-2">{{ $object['description'] }}</p>
                                <div class="flex items-center gap-2">
                                    <span class="text-lg font-bold text-info">{{ $count }}</span>
                                    @if ($newest)
                                        <span class="text-xs text-base-content/70">{{ $newest->created_at->diffForHumans() }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-card>
        @endif

        <!-- Block Types Section -->
        @if (count($pluginClass::getBlockTypes()) > 0)
        <x-card class="bg-base-200 shadow">
            <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                <x-icon name="o-cube" class="w-5 h-5 text-success" />
                Block Types
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                @foreach ($pluginClass::getBlockTypes() as $key => $block)
                    @php
                        $count = \App\Models\Block::where(function($query) use ($key) {
                            $query->where('block_type', $key)
                                  ->orWhereNull('block_type');
                        })
                            ->whereHas('event', function($query) use ($service) {
                                $query->where('service', $service)
                                      ->whereHas('integration', function($q) {
                                          $q->where('user_id', auth()->id());
                                      });
                            })
                            ->count();

                        $newest = \App\Models\Block::where(function($query) use ($key) {
                            $query->where('block_type', $key)
                                  ->orWhereNull('block_type');
                        })
                            ->whereHas('event', function($query) use ($service) {
                                $query->where('service', $service)
                                      ->whereHas('integration', function($q) {
                                          $q->where('user_id', auth()->id());
                                      });
                            })
                            ->orderBy('created_at', 'desc')
                            ->first();
                    @endphp

                    <div class="border border-base-200 bg-base-100 rounded-lg p-3 hover:bg-base-50 transition-colors">
                        <div class="flex items-start gap-3 mb-2">
                            <div class="w-8 h-8 rounded-full bg-success/10 flex items-center justify-center flex-shrink-0 mt-1">
                                <x-icon name="{{ $block['icon'] }}" class="w-4 h-4 text-success" />
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="font-medium text-base">{{ $block['display_name'] }}</div>
                                <p class="text-sm text-base-content/70 mb-2">{{ $block['description'] }}</p>
                                <div class="flex items-center gap-2">
                                    <span class="text-lg font-bold text-success">{{ $count }}</span>
                                    @if ($newest)
                                        <span class="text-xs text-base-content/70">{{ $newest->created_at->diffForHumans() }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-card>
        @endif

        <!-- Logs Section (if group exists) -->
        @if ($group)
        <x-card class="bg-base-200 shadow">
            <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                <x-icon name="o-document-text" class="w-5 h-5 text-primary" />
                Logs
            </h3>
            <p class="text-base-content/70 mb-4">View logs for all {{ $pluginClass::getDisplayName() }} integrations</p>
            <livewire:log-viewer type="group" :entity-id="$group->id" />
        </x-card>
        @endif
    </div>
</x-layouts.app>
