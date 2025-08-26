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
                            <label class="flex items-center gap-3 p-3 rounded-lg bg-base-100">
                                <input type="checkbox" name="types[]" value="{{ $key }}" class="checkbox" @checked(in_array($key, old('types', [])))>
                                <div>
                                    <div class="font-medium">{{ $meta['label'] ?? ucfirst($key) }}</div>
                                    @if (!empty($meta['description']))
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

                <!-- Available accounts for GoCardless -->
                @if (!empty($availableAccounts) && $group->service === 'gocardless')
                    <div class="p-4 bg-base-200 rounded-lg">
                        <div class="mb-4">
                            <h4 class="text-lg font-medium">{{ __('Available Bank Accounts') }}</h4>
                            <p class="text-sm text-base-content/70">{{ __('These accounts will be available for data fetching') }}</p>

                            @if (collect($availableAccounts)->contains('status', 'rate_limited'))
                                <div class="mt-2 p-3 bg-warning/20 border border-warning/30 rounded-lg">
                                    <div class="flex items-center gap-2">
                                        <x-icon name="o-exclamation-triangle" class="text-warning" />
                                        <div class="text-sm">
                                            <div class="font-medium text-warning">Rate Limit Notice</div>
                                            <div class="text-warning/80">Some account details are limited due to GoCardless API rate limits (4 requests/day). Full details will be available after the rate limit resets.</div>
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
                                        <div class="text-sm">This may be due to GoCardless API rate limits (4 requests/day).</div>
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

                    <!-- Per-type configuration sections (includes per-instance refresh time) -->
                    @foreach (($types ?? []) as $typeKey => $meta)
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

                                <!-- Per-instance update frequency -->
                                <div>
                                    <div class="mb-1 text-sm font-medium">{{ __('Update frequency (minutes)') }}</div>
                                    <x-input type="number" min="{{ $group->service === 'gocardless' ? 1440 : 5 }}" step="1" name="config[{{ $typeKey }}][update_frequency_minutes]" value="{{ old('config.'.$typeKey.'.update_frequency_minutes', $group->service === 'gocardless' ? 1440 : 60) }}" />
                                    @error('config[{{ $typeKey }}][update_frequency_minutes')
                                        <div class="text-xs text-error mt-1">{{ $message }}</div>
                                    @enderror
                                    <div class="text-xs text-base-content/70 mt-1">
                                        {{ __('How often to fetch data for this instance') }}
                                        @if ($group->service === 'gocardless')
                                            <br><span class="text-warning">⚠️ GoCardless has strict rate limits (4 requests/day). Recommended: 24+ hours.</span>
                                        @endif
                                    </div>
                                </div>

                                @foreach (($meta['schema'] ?? []) as $field => $config)
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
                                             <x-input type="number" name="config[{{ $typeKey }}][{{ $field }}]" min="{{ $config['min'] ?? 1 }}" value="{{ old('config.'.$typeKey.'.'.$field) }}" />
                                             @error('config.'.$typeKey.'.'.$field)
                                                 <div class="text-xs text-error mt-1">{{ $message }}</div>
                                             @enderror
                                        @elseif (($config['type'] ?? 'string') === 'string' && isset($config['options']))
                                              <select name="config[{{ $typeKey }}][{{ $field }}]" class="select select-bordered">
                                                  @foreach ($config['options'] ?? [] as $value => $label)
                                                      <option value="{{ $value }}" @selected(old('config.'.$typeKey.'.'.$field) == $value)>{{ $label }}</option>
                                                  @endforeach
                                              </select>
                                              @error('config.'.$typeKey.'.'.$field)
                                                  <div class="text-xs text-error mt-1">{{ $message }}</div>
                                              @enderror
                                        @else
                                             <x-input name="config[{{ $typeKey }}][{{ $field }}]" value="{{ old('config.'.$typeKey.'.'.$field) }}" />
                                             @error('config.'.$typeKey.'.'.$field)
                                                 <div class="text-xs text-error mt-1">{{ $message }}</div>
                                             @enderror
                                        @endif
                                        @if (isset($config['description']))
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


