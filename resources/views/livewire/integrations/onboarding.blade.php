@php
use Carbon\Carbon;
@endphp

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
                            @foreach (($types ?? []) as $key => $meta)
                                @php $hasPresets = !empty(($presets[$key] ?? [])); @endphp
                                @if ($hasPresets)
                                    @continue
                                @endif
                                @php
                                    $isMandatory = $meta['mandatory'] ?? false;
                                    $isChecked = in_array($key, old('types', array_keys($types ?? [])));
                                @endphp
                                <label class="flex items-center gap-3 p-3 rounded-lg bg-base-100 {{ $isMandatory ? 'border-2 border-primary' : '' }}">
                                    <input
                                        type="checkbox"
                                        name="types[]"
                                        value="{{ $key }}"
                                        class="checkbox"
                                        @checked($isChecked)
                                        @if ($isMandatory) disabled @endif
                                    >
                                    <div>
                                        <div class="font-medium flex items-center gap-2">
                                            {{ $meta['label'] ?? ucfirst($key) }}
                                            @if ($isMandatory)
                                                <span class="badge badge-primary badge-xs">Required</span>
                                            @endif
                                        </div>
                                        @if (!empty($meta['description']))
                                            <div class="text-xs text-base-content/70">{{ $meta['description'] }}</div>
                                        @endif
                                        @if ($isMandatory)
                                            <div class="text-xs text-primary mt-1">This instance type is required and cannot be disabled</div>
                                        @endif
                                    </div>
                                </label>
                            @endforeach
                        </div>
                        @error('types')
                            <div class="text-xs text-error mt-2">{{ $message }}</div>
                        @enderror
                    </div>

                    <!-- Available accounts for services that support it -->
                    @if (!empty($availableAccounts) && $group->service === 'gocardless')
                        <div class="p-4 bg-base-200 rounded-lg">
                            <div class="mb-4">
                                <h4 class="text-lg font-medium">{{ __('Available Accounts') }}</h4>
                                <p class="text-sm text-base-content/70">{{ __('These accounts will be available for data fetching') }}</p>

                                @if (collect($availableAccounts)->contains('status', 'rate_limited'))
                                    <div class="mt-2 p-3 bg-warning/20 border border-warning/30 rounded-lg">
                                        <div class="flex items-center gap-2">
                                            <x-icon name="o-exclamation-triangle" class="text-warning" />
                                            <div class="text-sm">
                                                <div class="font-medium text-warning">Rate Limit Notice</div>
                                                <div class="text-warning/80">Some account details are limited due to API rate limits. Full details will be available after the rate limit resets.</div>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            </div>

                            <div class="space-y-3">
                                @if (empty($availableAccounts))
                                    <div class="p-4 text-center bg-base-100 border border-base-300 rounded-lg">
                                        <div class="text-base-content/70">
                                            <div class="font-medium mb-2">No Account Details Available</div>
                                            <div class="text-sm">This may be due to API rate limits.</div>
                                            <div class="text-xs mt-1">Try refreshing the page later or contact support if the issue persists.</div>
                                        </div>
                                    </div>
                                @else
                                    @foreach ($availableAccounts as $account)
                                        <div class="p-3 rounded-lg bg-base-100 border border-base-300">
                                            <div class="flex items-center justify-between">
                                                <div>
                                                    <div class="font-medium">
                                                        @if (isset($account['details']) && !empty($account['details']))
                                                            {{ $account['details'] }}
                                                        @elseif (isset($account['ownerName']))
                                                            {{ $account['ownerName'] }}'s Account
                                                        @else
                                                            Account {{ substr($account['resourceId'] ?? $account['id'] ?? 'Unknown', 0, 8) }}
                                                        @endif
                                                    </div>
                                                    <div class="text-sm text-base-content/70">
                                                        @if (isset($account['currency']))
                                                            {{ $account['currency'] }}
                                                        @endif
                                                        @if (isset($account['cashAccountType']))
                                                            • {{ $account['cashAccountType'] }}
                                                        @endif
                                                        @if (isset($account['ownerName']))
                                                            • {{ $account['ownerName'] }}
                                                        @endif
                                                    </div>
                                                    @if (isset($account['maskedPan']))
                                                        <div class="text-xs text-base-content/50">Card ending {{ $account['maskedPan'] }}</div>
                                                    @endif
                                                    @if (isset($account['usage']))
                                                        <div class="text-xs text-base-content/50">{{ $account['usage'] }} account</div>
                                                    @endif
                                                </div>
                                                <div class="text-right">
                                                    @if (isset($account['status']) && $account['status'] === 'rate_limited')
                                                        <div class="text-sm font-medium text-warning">Rate Limited</div>
                                                        <div class="text-xs text-warning/70">{{ $account['rate_limit_error'] ?? 'API rate limit exceeded' }}</div>
                                                    @else
                                                        <div class="text-sm font-medium">{{ $account['status'] ?? 'Unknown Status' }}</div>
                                                        <div class="text-xs text-base-content/50">
                                                            {{ \Carbon\Carbon::parse($account['created'] ?? '')->format('M j, Y') ?? 'Unknown Date' }}
                                                        </div>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                @endif
                            </div>
                        </div>
                    @endif



                    @if (!empty($presets))
                        <div class="p-4 bg-base-200 rounded-lg">
                            <div class="mb-4">
                                <h4 class="text-lg font-medium">Task Presets</h4>
                                <p class="text-sm text-base-content/70">Choose which task presets to enable. You can override default values below.</p>
                            </div>

                            @foreach (($presets ?? []) as $typeKey => $presetList)
                                <div class="mb-3">
                                    <div class="font-medium mb-2">{{ $types[$typeKey]['label'] ?? ucfirst($typeKey) }}</div>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                        @foreach ($presetList as $preset)
                                            @php
                                                $presetKey = $preset['key'] ?? $preset['name'];
                                                $defaults = $preset['configuration'] ?? [];
                                            @endphp
                                            <div class="p-3 rounded-lg bg-base-100 border border-base-300" data-preset-wrapper data-type-key="{{ $typeKey }}" data-preset-key="{{ $presetKey }}">
                                                <label class="flex items-center gap-2">
                                                    <input type="checkbox" name="config[{{ $typeKey }}][presets][]" value="{{ $presetKey }}" class="checkbox preset-toggle" data-type-key="{{ $typeKey }}" data-preset-key="{{ $presetKey }}" data-defaults='{!! json_encode($defaults) !!}'
                                                        @checked(in_array($presetKey, old('config.'.$typeKey.'.presets', [])))>
                                                    <span class="font-medium">{{ $preset['name'] ?? 'Preset' }}</span>
                                                </label>
                                                <div class="grid grid-cols-1 md:grid-cols-2 gap-2 mt-2">
                                                    <x-input label="Task Queue" name="config[{{ $typeKey }}][preset_overrides][{{ $preset['key'] ?? $preset['name'] }}][task_queue]" value="{{ old('config.'.$typeKey.'.preset_overrides.'.($preset['key'] ?? $preset['name']).'.task_queue') }}" placeholder="pull" />
                                                    <x-input label="Use Schedule (0/1)" name="config[{{ $typeKey }}][preset_overrides][{{ $preset['key'] ?? $preset['name'] }}][use_schedule]" value="{{ old('config.'.$typeKey.'.preset_overrides.'.($preset['key'] ?? $preset['name']).'.use_schedule') }}" placeholder="1" />
                                                    <x-input label="Schedule Times (HH:mm)" name="config[{{ $typeKey }}][preset_overrides][{{ $preset['key'] ?? $preset['name'] }}][schedule_times]" value="{{ old('config.'.$typeKey.'.preset_overrides.'.($preset['key'] ?? $preset['name']).'.schedule_times') }}" placeholder="00:05" />
                                                    <x-input label="Schedule Timezone" name="config[{{ $typeKey }}][preset_overrides][{{ $preset['key'] ?? $preset['name'] }}][schedule_timezone]" value="{{ old('config.'.$typeKey.'.preset_overrides.'.($preset['key'] ?? $preset['name']).'.schedule_timezone') }}" placeholder="UTC" />
                                                    <x-textarea label="Task Payload (JSON)" rows="2" name="config[{{ $typeKey }}][preset_overrides][{{ $preset['key'] ?? $preset['name'] }}][task_payload]">{{ old('config.'.$typeKey.'.preset_overrides.'.($preset['key'] ?? $preset['name']).'.task_payload') }}</x-textarea>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <!-- Per-type configuration sections (includes per-instance refresh time); hide types that have presets -->
                    @foreach (($types ?? []) as $typeKey => $meta)
                        @php $hasPresets = !empty(($presets[$typeKey] ?? [])); @endphp
                        @if ($hasPresets)
                            @continue
                        @endif
                        <div class="p-4 bg-base-200 rounded-lg">
                            <div class="mb-4">
                                <h4 class="text-lg font-medium">{{ $meta['label'] ?? ucfirst($typeKey) }}</h4>
                                @if (!empty($meta['description']))
                                    <p class="text-sm text-base-content/70">{{ $meta['description'] }}</p>
                                @endif
                            </div>

                            <div class="space-y-3">
                                <!-- Instance display name (defaults to label) -->
                                <div>
                                    <div class="mb-1 text-sm font-medium">{{ __('Instance Name') }}</div>
                                    <x-input name="config[{{ $typeKey }}][name]" value="{{ old('config.'.$typeKey.'.name', $meta['label'] ?? ucfirst($typeKey)) }}" />
                                </div>

                                @foreach (($meta['schema'] ?? []) as $field => $config)
                                    @php
                                        $pluginClass = \App\Integrations\PluginRegistry::getPlugin($group->service);
                                        $isWebhook = $pluginClass && $pluginClass::getServiceType() === 'webhook';
                                        $shouldHideField = $isWebhook && $field === 'update_frequency_minutes';
                                    @endphp
                                    @if (!$shouldHideField)
                                    <div>
                                        <div class="mb-1 text-sm font-medium">{{ $config['label'] ?? ucfirst($field) }}</div>
                                        @if (($config['type'] ?? 'string') === 'array' && isset($config['options']))
                                            @foreach ($config['options'] as $value => $label)
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
                                        @elseif (($config['type'] ?? 'string') === 'array')
                                             <x-textarea name="config[{{ $typeKey }}][{{ $field }}]" rows="3" placeholder="{{ __('Comma-separated values') }}" value="{{ old('config.'.$typeKey.'.'.$field) }}" />
                                             @error('config.'.$typeKey.'.'.$field)
                                                 <div class="text-xs text-error mt-1">{{ $message }}</div>
                                             @enderror
                                        @elseif (($config['type'] ?? 'string') === 'integer')
                                             <x-input type="number" name="config[{{ $typeKey }}][{{ $field }}]" min="{{ $config['min'] ?? 1 }}" value="{{ old('config.'.$typeKey.'.'.$field, $config['default'] ?? '') }}" />
                                             @error('config.'.$typeKey.'.'.$field)
                                                 <div class="text-xs text-error mt-1">{{ $message }}</div>
                                             @enderror
                                        @elseif (($config['type'] ?? 'string') === 'string' && isset($config['options']))
                                              <select name="config[{{ $typeKey }}][{{ $field }}]" class="select select-bordered">
                                                  @foreach ($config['options'] ?? [] as $value => $label)
                                                      <option value="{{ $value }}" @selected(old('config.'.$typeKey.'.'.$field, $config['default'] ?? '') == $value)>{{ $label }}</option>
                                                  @endforeach
                                              </select>
                                              @error('config.'.$typeKey.'.'.$field)
                                                  <div class="text-xs text-error mt-1">{{ $message }}</div>
                                              @enderror
                                        @else
                                             <x-input name="config[{{ $typeKey }}][{{ $field }}]" value="{{ old('config.'.$typeKey.'.'.$field, $config['default'] ?? '') }}" />
                                             @if ($field === 'task_job_class' && !empty(($presets[$typeKey] ?? [])))
                                                 <div class="text-xs text-base-content/70 mt-1">Tip: Selecting a preset above will set sensible defaults. You can still override them here.</div>
                                             @endif
                                             @error('config.'.$typeKey.'.'.$field)
                                                 <div class="text-xs text-error mt-1">{{ $message }}</div>
                                             @enderror
                                        @endif
                                        @if (isset($config['description']))
                                            <div class="text-xs text-base-content/70 mt-1">{{ $config['description'] }}</div>
                                        @endif
                                    </div>
                                    @endif
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

<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.preset-toggle').forEach(cb => {
        cb.addEventListener('change', (e) => {
            const checkbox = e.target;
            const wrapper = checkbox.closest('[data-preset-wrapper]');
            const defaults = checkbox.dataset.defaults ? JSON.parse(checkbox.dataset.defaults) : {};
            if (!wrapper) return;
            const typeKey = wrapper.getAttribute('data-type-key');
            const presetKey = wrapper.getAttribute('data-preset-key');

            const q = (name) => wrapper.querySelector(`[name="config[${typeKey}][preset_overrides][${presetKey}][${name}]\"]`);

            if (checkbox.checked) {
                // Populate override inputs with defaults for a better UX
                const setIfEmpty = (el, val) => { if (el && (!el.value || el.value.trim() === '')) el.value = Array.isArray(val) ? val.join(' ') : (val ?? ''); };
                setIfEmpty(q('task_queue'), defaults.task_queue ?? defaults.taskQueue ?? 'pull');
                setIfEmpty(q('use_schedule'), (defaults.use_schedule ?? 0).toString());
                setIfEmpty(q('schedule_times'), (defaults.schedule_times ?? []).join(' '));
                setIfEmpty(q('schedule_timezone'), defaults.schedule_timezone ?? 'UTC');
                const payloadEl = q('task_payload');
                if (payloadEl && (!payloadEl.value || payloadEl.value.trim() === '')) {
                    if (defaults.task_payload) {
                        try { payloadEl.value = JSON.stringify(defaults.task_payload); } catch (_) {}
                    }
                }
            }
        });
    });
});
</script>


