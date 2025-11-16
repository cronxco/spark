<div>
    <!-- Two-column layout: main content + drawer -->
    <div class="flex flex-col lg:flex-row gap-4 lg:gap-6">
        <!-- Main Content Area -->
        <div class="flex-1 space-y-4 lg:space-y-6">
            <!-- Header -->
            <x-header title="{{ $integration->name ?: $integration->service }}" subtitle="{{ $integration->service }} integration" separator>
                <x-slot:actions>
                    <!-- Desktop: Full buttons -->
                    <div class="hidden sm:flex gap-2">
                        <x-button
                            label="Configure"
                            link="{{ route('integrations.configure', $integration->id) }}"
                            class="btn-outline"
                            icon="o-cog-6-tooth"
                        />
                        <x-button
                            wire:click="toggleSidebar"
                            class="btn-ghost btn-sm">
                            <x-icon name="{{ $showSidebar ? 'o-x-mark' : 'o-adjustments-horizontal' }}" class="w-5 h-5" />
                        </x-button>
                    </div>

                    <!-- Mobile: Dropdown -->
                    <div class="sm:hidden">
                        <x-dropdown>
                            <x-slot:trigger>
                                <x-button class="btn-ghost btn-sm">
                                    <x-icon name="o-ellipsis-vertical" class="w-5 h-5" />
                                </x-button>
                            </x-slot:trigger>
                            <x-menu-item title="Configure" icon="o-cog-6-tooth" link="{{ route('integrations.configure', $integration->id) }}" />
                            <x-menu-item title="{{ $showSidebar ? 'Hide Details' : 'Show Details' }}" icon="{{ $showSidebar ? 'o-x-mark' : 'o-adjustments-horizontal' }}" wire:click="toggleSidebar" />
                        </x-dropdown>
                    </div>
                </x-slot:actions>
            </x-header>

            <!-- Primary Hero Card -->
            <x-card>
                <div class="flex flex-col sm:flex-row items-start gap-4 lg:gap-6">
                    <!-- Large integration icon -->
                    <div class="flex-shrink-0 self-center sm:self-start">
                        @php
                            $pluginClass = $this->getPluginClass();
                            $icon = $pluginClass ? $pluginClass::getIcon() : 'o-link';
                        @endphp
                        <div class="w-12 h-12 sm:w-16 sm:h-16 rounded-full bg-primary/10 flex items-center justify-center">
                            <x-icon name="{{ $icon }}" class="w-6 h-6 sm:w-8 sm:h-8 text-primary" />
                        </div>
                    </div>

                    <!-- Main content -->
                    <div class="flex-1">
                        <div class="mb-4 text-center sm:text-left">
                            <h2 class="text-xl sm:text-2xl lg:text-3xl font-bold text-base-content mb-2">
                                {{ $integration->name ?: $integration->service }}
                            </h2>
                            <div class="text-sm text-base-content/70">
                                {{ $integration->instance_type }}
                            </div>
                        </div>

                        <!-- Key metadata -->
                        <div class="flex flex-wrap items-center justify-center sm:justify-start gap-4 text-sm">
                            @if ($integration->last_successful_update_at)
                                <div class="flex items-center gap-2">
                                    <x-icon name="o-clock" class="w-4 h-4 text-base-content/60" />
                                    <span class="text-base-content/70">Last update: {{ $integration->last_successful_update_at->diffForHumans() }}</span>
                                </div>
                            @else
                                <div class="flex items-center gap-2">
                                    <x-icon name="o-exclamation-triangle" class="w-4 h-4 text-warning" />
                                    <span class="text-warning">Never updated</span>
                                </div>
                            @endif

                            @if ($integration->needsUpdate())
                                <x-badge value="Needs update" class="badge-warning" />
                            @endif

                            @if ($integration->isPaused())
                                <x-badge value="Paused" class="badge-neutral" />
                            @endif
                        </div>

                        <!-- Update schedule info -->
                        @if ($integration->getNextUpdateTime())
                            <div class="mt-4 p-3 lg:p-4 rounded-lg bg-base-300/50 border border-base-300">
                                <div class="flex items-center gap-2 text-sm">
                                    <x-icon name="o-arrow-path" class="w-4 h-4 text-base-content/60" />
                                    <span class="text-base-content/70">Next update: {{ $integration->getNextUpdateTime()->diffForHumans() }}</span>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </x-card>

            @if ($this->getPluginClass())
                <!-- Action Types Overview -->
                @if ($this->getActionTypes()->count() > 0)
                <x-card class="bg-base-200 shadow">
                    <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                        <x-icon name="o-bolt" class="w-5 h-5 text-primary" />
                        Action Types
                    </h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        @foreach ($this->getActionTypes() as $actionType)
                        <div class="border border-base-200 bg-base-100 rounded-lg p-3 hover:bg-base-50 transition-colors">
                            <div class="flex items-start gap-3 mb-2">
                                <div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center flex-shrink-0">
                                    <x-icon name="{{ $actionType['action']['icon'] }}" class="w-4 h-4 text-primary" />
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="font-medium text-base truncate">{{ $actionType['action']['display_name'] }}</div>
                                    <div class="text-sm text-base-content/70 flex items-center gap-2">
                                        <span class="text-lg font-bold text-primary">{{ $actionType['count'] }}</span>
                                        @if ($actionType['newest'])
                                        <span class="text-xs">{{ $actionType['newest']->created_at->diffForHumans() }}</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </x-card>
                @endif

                <!-- Object Types Overview -->
                @if ($this->getObjectTypes()->count() > 0)
                <x-card class="bg-base-200 shadow">
                    <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                        <x-icon name="o-squares-2x2" class="w-5 h-5 text-info" />
                        Object Types
                    </h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        @foreach ($this->getObjectTypes() as $objectType)
                        <div class="border border-base-200 bg-base-100 rounded-lg p-3 hover:bg-base-50 transition-colors">
                            <div class="flex items-start gap-3 mb-2">
                                <div class="w-8 h-8 rounded-full bg-info/10 flex items-center justify-center flex-shrink-0">
                                    <x-icon name="{{ $objectType['object']['icon'] }}" class="w-4 h-4 text-info" />
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="font-medium text-base truncate">{{ $objectType['object']['display_name'] }}</div>
                                    <div class="text-sm text-base-content/70 flex items-center gap-2">
                                        <span class="text-lg font-bold text-info">{{ $objectType['count'] }}</span>
                                        @if ($objectType['newest'])
                                        <span class="text-xs">{{ $objectType['newest']->created_at->diffForHumans() }}</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </x-card>
                @endif

                <!-- Block Types Overview -->
                @if ($this->getBlockTypes()->count() > 0)
                <x-card class="bg-base-200 shadow">
                    <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                        <x-icon name="o-cube" class="w-5 h-5 text-success" />
                        Block Types
                    </h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        @foreach ($this->getBlockTypes() as $blockType)
                        <div class="border border-base-200 bg-base-100 rounded-lg p-3 hover:bg-base-50 transition-colors">
                            <div class="flex items-start gap-3 mb-2">
                                <div class="w-8 h-8 rounded-full bg-success/10 flex items-center justify-center flex-shrink-0">
                                    <x-icon name="{{ $blockType['block']['icon'] }}" class="w-4 h-4 text-success" />
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="font-medium text-base truncate">{{ $blockType['block']['display_name'] }}</div>
                                    <div class="text-sm text-base-content/70 flex items-center gap-2">
                                        <span class="text-lg font-bold text-success">{{ $blockType['count'] }}</span>
                                        @if ($blockType['newest'])
                                        <span class="text-xs">{{ $blockType['newest']->created_at->diffForHumans() }}</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </x-card>
                @endif
            @else
                <!-- No plugin configuration -->
                <x-card class="bg-base-200 shadow">
                    <div class="text-center py-8">
                        <x-icon name="o-exclamation-triangle" class="w-16 h-16 mx-auto text-base-content/70 mb-4" />
                        <h3 class="text-lg font-medium text-base-content mb-2">Plugin Configuration Not Found</h3>
                        <p class="text-base-content/70">Plugin configuration not found for this integration.</p>
                    </div>
                </x-card>
            @endif
        </div>

        <!-- Drawer for Technical Details -->
        <x-drawer wire:model="showSidebar" right title="Integration Details" separator with-close-button class="w-11/12 lg:w-1/3">
            <div class="space-y-4">
                <!-- Primary Information (Always Visible) -->
                <div class="pb-4 border-b border-base-200">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-semibold uppercase tracking-wider text-base-content/80">Information</h3>
                        <button
                            wire:click="exportAsJson"
                            class="btn btn-ghost btn-xs gap-1"
                            title="Export complete integration with configuration and recent events">
                            <x-icon name="o-arrow-down-tray" class="w-3 h-3" />
                            <span class="hidden sm:inline">Export</span>
                        </button>
                    </div>
                    <dl>
                        <x-metadata-row label="Integration ID" :value="$integration->id" copyable />
                        <x-metadata-row label="Service" :value="$integration->service" />
                        <x-metadata-row label="Instance Type" :value="$integration->instance_type" />
                        <x-metadata-row label="Status">
                            <span class="badge {{ $integration->isPaused() ? 'badge-neutral' : 'badge-success' }} badge-sm">
                                {{ $integration->isPaused() ? 'Paused' : 'Active' }}
                            </span>
                        </x-metadata-row>
                        <x-metadata-row label="Created">
                            <x-uk-date :date="$integration->created_at" />
                        </x-metadata-row>
                        @if ($integration->last_triggered_at)
                            <x-metadata-row label="Last Triggered">
                                <x-uk-date :date="$integration->last_triggered_at" />
                            </x-metadata-row>
                        @endif
                        @if ($integration->last_successful_update_at)
                            <x-metadata-row label="Last Successful Update">
                                <x-uk-date :date="$integration->last_successful_update_at" />
                            </x-metadata-row>
                        @endif
                    </dl>
                </div>

                <!-- Configuration (Collapsible, Default Open) -->
                <x-collapse wire:model="configOpen">
                    <x-slot:heading>
                        <div class="text-sm font-semibold uppercase tracking-wider text-base-content/80 flex items-center gap-2">
                            <x-icon name="o-cog-6-tooth" class="w-4 h-4" />
                            Configuration
                        </div>
                    </x-slot:heading>
                    <x-slot:content>
                        <dl>
                            @if ($integration->getUpdateFrequencyMinutes())
                                <x-metadata-row
                                    label="Update Frequency"
                                    :value="$integration->getUpdateFrequencyMinutes() . ' minutes'"
                                />
                            @endif

                            @if ($integration->useSchedule())
                                <x-metadata-row
                                    label="Schedule Times"
                                    :value="implode(', ', $integration->getScheduleTimes())"
                                />
                                <x-metadata-row
                                    label="Schedule Timezone"
                                    :value="$integration->getScheduleTimezone()"
                                />
                            @endif

                            @if ($integration->account_id)
                                <x-metadata-row
                                    label="Account ID"
                                    :value="$integration->account_id"
                                    copyable
                                />
                            @endif

                            @if ($integration->getNextUpdateTime())
                                <x-metadata-row label="Next Update">
                                    <x-uk-date :date="$integration->getNextUpdateTime()" />
                                </x-metadata-row>
                            @endif
                        </dl>

                        <div class="mt-4 pt-4 border-t border-base-200">
                            <x-button
                                label="Edit Configuration"
                                link="{{ route('integrations.configure', $integration->id) }}"
                                class="btn-outline btn-sm w-full"
                                icon="o-pencil"
                            />
                        </div>
                    </x-slot:content>
                </x-collapse>

                <!-- Logs (Collapsible, Default Closed) -->
                <x-collapse wire:model="logsOpen">
                    <x-slot:heading>
                        <div class="text-sm font-semibold uppercase tracking-wider text-base-content/80 flex items-center gap-2">
                            <x-icon name="o-document-text" class="w-4 h-4" />
                            Logs
                        </div>
                    </x-slot:heading>
                    <x-slot:content>
                        <livewire:log-viewer type="integration" :entity-id="$integration->id" />
                    </x-slot:content>
                </x-collapse>
            </div>
        </x-drawer>
    </div>
</div>
