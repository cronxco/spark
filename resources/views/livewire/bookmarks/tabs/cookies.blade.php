<!-- Add Cookies Section -->
<div class="card bg-base-200 shadow mb-6">
    <div class="card-body">
        <h3 class="text-lg font-semibold mb-4">Add Domain Cookies</h3>
        <form wire:submit="addCookies" class="space-y-4">
            <div class="form-control">
                <label class="label">
                    <span class="label-text">Domain</span>
                </label>
                <input
                    type="text"
                    wire:model="cookieDomain"
                    placeholder="example.com"
                    class="input input-bordered w-full"
                    required />
                @error('cookieDomain')
                <label class="label">
                    <span class="label-text-alt text-error">{{ $message }}</span>
                </label>
                @enderror
            </div>
            <div class="form-control">
                <label class="label">
                    <span class="label-text">Cookies (JSON)</span>
                </label>
                <br />
                <textarea
                    wire:model="cookieJson"
                    placeholder='[{"name": "session_id", "value": "abc123", "expires": 1733155200}]'
                    class="textarea textarea-bordered h-32 font-mono text-sm"
                    required></textarea>
                @error('cookieJson')
                <label class="label">
                    <span class="label-text-alt text-error">{{ $message }}</span>
                </label>
                @enderror
            </div>
            <div class="flex gap-2">
                <x-button type="submit" class="btn-primary">
                    <x-icon name="fas.plus" class="w-4 h-4" />
                    Add Cookies
                </x-button>
            </div>
        </form>

        @if ($playwrightEnabled && $playwrightAvailable)
        <x-alert icon="fas.circle-info" class="alert-info alert-soft mt-4">
            <div>
                <p class="font-semibold">Need to access a logged-in site?</p>
                <p class="text-sm"><a href="{{ config('services.playwright.chrome_vnc_url') }}" target="_blank" class="link link-primary text-sm">Open the browser</a>, log in to any site, then come back here to save your session cookies.</p>
            </div>
            <x-slot:actions>
                <x-button type="button" wire:click="extractCookiesFromBrowser" class="btn-info btn-outline">
                    <x-icon name="fas.globe" class="w-4 h-4" />
                    Extract from Browser
                </x-button>
            </x-slot:actions>
        </x-alert>
        @endif

        <!-- Format Help -->
        <x-collapse class="mt-6">
            <x-slot:heading>
                <div class="flex items-center gap-2">
                    <x-icon name="o-question-mark-circle" class="w-5 h-5" />
                    Supported Formats
                </div>
            </x-slot:heading>
            <x-slot:content>
                <div class="prose prose-sm max-w-none">
                    <p class="text-sm text-base-content/70">Fetch supports multiple cookie formats:</p>
                    <pre class="text-xs"><code>// Standard format with expiry
[{"name": "session_id", "value": "abc123", "expires": 1733155200}]

// Simple key-value
{"session_id": "abc123", "auth_token": "xyz789"}

// Browser HAR format
[{"name": "session_id", "value": "abc123", "expirationDate": 1733155200}]</code></pre>
                </div>
            </x-slot:content>
        </x-collapse>
    </div>
</div>

<!-- Domains List -->
@if (count($domains) > 0)
<div class="space-y-4">
    @foreach ($domains as $domain)
    <div class="card bg-base-200 shadow">
        <div class="card-body">
            <div class="flex items-start justify-between mb-4">
                <div class="flex-1">
                    <h3 class="text-lg font-semibold mb-2">{{ $domain['domain'] }}</h3>
                    <div class="flex flex-wrap gap-2 items-center text-sm">
                        <!-- Expiry Badge -->
                        @if ($domain['expires_at'])
                        @php
                        $expiryDate = \Carbon\Carbon::parse($domain['expires_at']);
                        $badgeClass = match($domain['expiry_status']) {
                        'green' => 'badge-success',
                        'yellow' => 'badge-warning',
                        'red' => 'badge-error',
                        default => 'badge-neutral',
                        };
                        @endphp
                        <x-badge value="Expires {{ $expiryDate->format('M j') }}" class="{{ $badgeClass }} badge-outline" />
                        @else
                        <x-badge value="No expiry set" class="badge-neutral badge-outline" />
                        @endif

                        <span class="text-base-content/70">{{ $domain['cookie_count'] }} cookies</span>

                        @if ($domain['last_used_at'])
                        <span class="text-base-content/70">
                            Used {{ \Carbon\Carbon::parse($domain['last_used_at'])->diffForHumans() }}
                        </span>
                        @else
                        <span class="text-base-content/70">Never used</span>
                        @endif
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex gap-2">
                    <x-button
                        wire:click="testDomain('{{ $domain['domain'] }}')"
                        class="btn-outline btn-sm">
                        <x-icon name="fas.flask" class="w-4 h-4" />
                        Test
                    </x-button>
                    <x-button
                        wire:click="deleteCookies('{{ $domain['domain'] }}')"
                        class="btn-error btn-outline btn-sm">
                        <x-icon name="fas.trash" class="w-4 h-4" />
                        Delete
                    </x-button>
                </div>
            </div>

            <!-- Auto-Refresh Toggle Section -->
            <div class="border-t border-base-300 pt-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input
                                type="checkbox"
                                class="toggle toggle-primary toggle-sm"
                                @if ($domain['auto_refresh_enabled']) checked @endif
                                wire:click="toggleCookieAutoRefresh('{{ $domain['domain'] }}')" />
                            <span class="text-sm font-medium">Auto-refresh cookies before expiry</span>
                        </label>
                        <div class="tooltip" data-tip="Automatically refresh cookies before they expire using Playwright">
                            <x-icon name="fas.circle-info" class="w-4 h-4 text-base-content/50" />
                        </div>
                    </div>
                </div>

                <!-- Status Info -->
                @if ($domain['auto_refresh_enabled'] || $domain['updated_at'] || $domain['last_refreshed_at'])
                <div class="mt-2 flex flex-wrap gap-3 text-xs text-base-content/70">
                    @if ($domain['updated_at'])
                    <span class="flex items-center gap-1">
                        <x-icon name="fas.clock" class="w-3 h-3" />
                        Auto-updated {{ \Carbon\Carbon::parse($domain['updated_at'])->diffForHumans() }}
                    </span>
                    @endif
                    @if ($domain['last_refreshed_at'])
                    <span class="flex items-center gap-1">
                        <x-icon name="fas.rotate" class="w-3 h-3" />
                        Last refreshed {{ \Carbon\Carbon::parse($domain['last_refreshed_at'])->diffForHumans() }}
                    </span>
                    @endif
                </div>
                @endif
            </div>
        </div>
    </div>
    @endforeach
</div>
@else
<div class="card bg-base-200 shadow">
    <div class="card-body">
        <div class="text-center py-12">
            <x-icon name="fas.lock" class="w-16 h-16 mx-auto text-base-content/70 mb-4" />
            <h3 class="text-lg font-medium text-base-content mb-2">No saved sessions yet</h3>
            <p class="text-base-content/70">
                Add cookies to access sites that require login.
            </p>
        </div>
    </div>
</div>
@endif
