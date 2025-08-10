<x-layouts.app :title="'Onboarding'">
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <x-card :title="__('Configure ' . ($pluginName ?? ucfirst($group->service)))" shadow>
                <!-- Intro section to match configure page style -->
                <div class="mb-6 p-4 bg-base-200 rounded-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <h4 class="text-lg font-medium">{{ __('Choose instances to set up') }}</h4>
                            <p class="text-sm text-base-content/70">{{ __('Select one or more instance types and set initial options. You can add more later.') }}</p>
                        </div>
                    </div>
                </div>

                <form method="POST" action="{{ route('integrations.storeInstances', ['group' => $group->id]) }}" class="space-y-6">
                    @csrf

                    <!-- Instance type selection -->
                    <div class="p-4 bg-base-200 rounded-lg">
                        <div class="mb-4">
                            <h4 class="text-lg font-medium">{{ __('Instance types') }}</h4>
                            <p class="text-sm text-base-content/70">{{ __('Tick the types you want to create now') }}</p>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            @foreach(($types ?? []) as $key => $meta)
                                <label class="flex items-center gap-3 p-3 rounded-lg bg-base-100">
                                    <input type="checkbox" name="types[]" value="{{ $key }}" class="checkbox" @checked(in_array($key, old('types', [])))>
                                    <div>
                                        <div class="font-medium">{{ $meta['label'] ?? ucfirst($key) }}</div>
                                        @if(!empty($meta['description']))
                                            <div class="text-xs text-base-content/70">{{ $meta['description'] }}</div>
                                        @endif
                                    </div>
                                </label>
                            @endforeach
                        </div>
                        @error('types')
                            <div class="text-xs text-error mt-2">{{ $message }}</div>
                        @enderror
                    </div>

                    <!-- Per-type configuration sections (includes per-instance refresh time) -->
                    @foreach(($types ?? []) as $typeKey => $meta)
                        <div class="p-4 bg-base-200 rounded-lg">
                            <div class="mb-4">
                                <h4 class="text-lg font-medium">{{ $meta['label'] ?? ucfirst($typeKey) }}</h4>
                                @if(!empty($meta['description']))
                                    <p class="text-sm text-base-content/70">{{ $meta['description'] }}</p>
                                @endif
                            </div>

                            <div class="space-y-3">
                                <!-- Instance display name (defaults to label) -->
                                <div>
                                    <div class="mb-1 text-sm font-medium">{{ __('Instance Name') }}</div>
                                    <x-input name="config[{{ $typeKey }}][name]" value="{{ old('config.'.$typeKey.'.name', $meta['label'] ?? ucfirst($typeKey)) }}" />
                                </div>

                                <!-- Per-instance update frequency -->
                                <div>
                                    <div class="mb-1 text-sm font-medium">{{ __('Update frequency (minutes)') }}</div>
                                    <x-input type="number" min="5" name="config[{{ $typeKey }}][update_frequency_minutes]" value="{{ old('config.'.$typeKey.'.update_frequency_minutes', 60) }}" />
                                    <div class="text-xs text-base-content/70 mt-1">{{ __('How often to fetch data for this instance') }}</div>
                                </div>

                                @foreach(($meta['schema'] ?? []) as $field => $config)
                                    <div>
                                        <div class="mb-1 text-sm font-medium">{{ $config['label'] ?? ucfirst($field) }}</div>
                                        @if(($config['type'] ?? 'string') === 'array' && isset($config['options']))
                                            @foreach($config['options'] as $value => $label)
                                                <label class="flex items-center gap-2">
                                                    <input type="checkbox" name="config[{{ $typeKey }}][{{ $field }}][]" value="{{ $value }}" class="checkbox" @checked(in_array($value, old('config.'.$typeKey.'.'.$field, [])))>
                                                    <span>{{ $label }}</span>
                                                </label>
                                            @endforeach
                                             @error('config.'.$typeKey.'.'.$field)
                                                 <div class="text-xs text-error mt-1">{{ $message }}</div>
                                             @enderror
                                             @error('config.'.$typeKey.'.'.$field.'.*')
                                                 <div class="text-xs text-error mt-1">{{ $message }}</div>
                                             @enderror
                                        @elseif(($config['type'] ?? 'string') === 'array')
                                             <x-textarea name="config[{{ $typeKey }}][{{ $field }}]" rows="3" placeholder="{{ __('Comma-separated values') }}" value="{{ old('config.'.$typeKey.'.'.$field) }}" />
                                             @error('config.'.$typeKey.'.'.$field)
                                                 <div class="text-xs text-error mt-1">{{ $message }}</div>
                                             @enderror
                                        @elseif(($config['type'] ?? 'string') === 'integer')
                                             <x-input type="number" name="config[{{ $typeKey }}][{{ $field }}]" min="{{ $config['min'] ?? 1 }}" value="{{ old('config.'.$typeKey.'.'.$field) }}" />
                                             @error('config.'.$typeKey.'.'.$field)
                                                 <div class="text-xs text-error mt-1">{{ $message }}</div>
                                             @enderror
                                        @else
                                             <x-input name="config[{{ $typeKey }}][{{ $field }}]" value="{{ old('config.'.$typeKey.'.'.$field) }}" />
                                             @error('config.'.$typeKey.'.'.$field)
                                                 <div class="text-xs text-error mt-1">{{ $message }}</div>
                                             @enderror
                                        @endif
                                        @if(isset($config['description']))
                                            <div class="text-xs text-base-content/70 mt-1">{{ $config['description'] }}</div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach

                    <div class="p-4 bg-base-200 rounded-lg space-y-3">
                        <label class="flex items-center gap-3">
                            <input type="checkbox" name="run_migration" class="checkbox" @checked(old('run_migration', false))>
                            <span class="font-medium">{{ __('Run initial historical import now') }}</span>
                        </label>
                        <div class="text-xs text-base-content/70">
                            {{ __('This queues a one-time backfill on the migration queue. It may take a while depending on data size and API limits.') }}
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <div class="mb-1 text-sm font-medium">{{ __('Historic import time limit (minutes, optional)') }}</div>
                                <x-input type="number" min="1" name="migration_timebox_minutes" value="{{ old('migration_timebox_minutes') }}" placeholder="{{ __('Leave blank for no limit') }}" />
                                <div class="text-xs text-base-content/70 mt-1">
                                    {{ __('If set, the migration will stop when this many minutes have elapsed since queueing. Useful for providers with post-auth timeboxes (e.g. Monzo).') }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end space-x-3 pt-6 border-t border-base-300">
                        <x-button 
                            label="{{ __('Cancel') }}"
                            link="{{ route('integrations.index') }}"
                            class="btn-outline"
                        />
                        <x-button 
                            label="{{ __('Create Instances') }}"
                            type="submit"
                            class="btn-primary"
                        />
                    </div>
                </form>
            </x-card>
        </div>
    </div>
</x-layouts.app>


