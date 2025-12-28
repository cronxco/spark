            <div class="space-y-6" wire:poll.30s="refreshMetrics">
                <!-- Status Card -->
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-semibold">Playwright Status</h3>
                            <span class="text-xs text-base-content/50">Auto-refreshes every 30s</span>
                        </div>
                        <div class="flex items-center gap-4">
                            <div class="flex-1">
                                <p class="text-sm text-base-content/70">Service Status</p>
                                <p class="font-semibold">
                                    @if ($playwrightAvailable)
                                    <x-badge value="Available" class="badge-success" />
                                    @else
                                    <x-badge value="Unavailable" class="badge-error" />
                                    @endif
                                </p>
                            </div>
                            @if ($playwrightAvailable && !empty($workerStats))
                            <div class="stats stats-vertical sm:stats-horizontal shadow-sm">
                                <div class="stat py-2 px-4">
                                    <div class="stat-title text-xs">Stealth</div>
                                    <div class="stat-value text-sm">
                                        @if ($workerStats['stealth_enabled'])
                                        <x-badge value="Enabled" class="badge-success badge-sm" />
                                        @else
                                        <x-badge value="Disabled" class="badge-neutral badge-sm" />
                                        @endif
                                    </div>
                                </div>
                                <div class="stat py-2 px-4">
                                    <div class="stat-title text-xs">Context TTL</div>
                                    <div class="stat-value text-sm">{{ $workerStats['context_ttl'] }}m</div>
                                </div>
                            </div>
                            @endif
                            @if ($playwrightAvailable)
                            <a href="{{ config('services.playwright.chrome_vnc_url') }}" target="_blank" class="btn btn-primary btn-sm">
                                <x-icon name="fas.desktop" class="w-4 h-4" />
                                Open Browser (VNC)
                            </a>
                            @endif
                        </div>

                        @if (!$playwrightAvailable)
                        <div class="alert alert-warning mt-4">
                            <x-icon name="fas.triangle-exclamation" class="w-5 h-5" />
                            <div>
                                <p class="font-semibold">Browser automation unavailable</p>
                                <p class="text-sm">To enable, run: <code class="bg-base-300 px-2 py-1 rounded">sail up -d --profile playwright</code></p>
                            </div>
                        </div>
                        @endif
                    </div>
                </div>

                <!-- Real-time Metrics Card -->
                @if ($playwrightAvailable && !empty($healthMetrics))
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <h3 class="text-lg font-semibold mb-4">Real-time Metrics (Last 24h)</h3>

                        <!-- Success Rate Comparison -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
                            <div class="card bg-base-100 shadow-sm">
                                <div class="card-body">
                                    <h4 class="font-medium mb-2">HTTP Success Rate</h4>
                                    <div class="flex items-end gap-2">
                                        <span class="text-3xl font-bold text-secondary">{{ $healthMetrics['http']['success_rate'] }}%</span>
                                        <span class="text-sm text-base-content/70 mb-1">
                                            ({{ $healthMetrics['http']['success'] }}/{{ $healthMetrics['http']['total'] }})
                                        </span>
                                    </div>
                                    <progress class="progress progress-secondary w-full mt-2" value="{{ $healthMetrics['http']['success_rate'] }}" max="100"></progress>
                                    <p class="text-xs text-base-content/70 mt-2">
                                        Avg: {{ $healthMetrics['http']['avg_duration_ms'] }}ms
                                    </p>
                                </div>
                            </div>

                            <div class="card bg-base-100 shadow-sm">
                                <div class="card-body">
                                    <h4 class="font-medium mb-2">Playwright Success Rate</h4>
                                    <div class="flex items-end gap-2">
                                        <span class="text-3xl font-bold text-primary">{{ $healthMetrics['playwright']['success_rate'] }}%</span>
                                        <span class="text-sm text-base-content/70 mb-1">
                                            ({{ $healthMetrics['playwright']['success'] }}/{{ $healthMetrics['playwright']['total'] }})
                                        </span>
                                    </div>
                                    <progress class="progress progress-primary w-full mt-2" value="{{ $healthMetrics['playwright']['success_rate'] }}" max="100"></progress>
                                    <p class="text-xs text-base-content/70 mt-2">
                                        Avg: {{ $healthMetrics['playwright']['avg_duration_ms'] }}ms
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Total Fetches Today -->
                        <div class="stats shadow w-full">
                            <div class="stat">
                                <div class="stat-title">Total Fetches Today</div>
                                <div class="stat-value text-primary">{{ $healthMetrics['total_fetches'] }}</div>
                                <div class="stat-desc">
                                    HTTP: {{ $healthMetrics['http']['total'] }} |
                                    Playwright: {{ $healthMetrics['playwright']['total'] }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Stealth Effectiveness Card -->
                @if ($playwrightAvailable && !empty($healthMetrics['stealth']) && $healthMetrics['stealth']['total'] > 0)
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <h3 class="text-lg font-semibold mb-4">Stealth Effectiveness</h3>
                        <div class="flex items-center gap-6">
                            @php
                            $effectiveness = $healthMetrics['stealth']['effectiveness'];
                            @endphp
                            <div class="radial-progress text-accent" style="--value: {{ $effectiveness }};" role="progressbar">
                                {{ $effectiveness }}%
                            </div>
                            <div class="flex-1">
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <p class="text-sm text-base-content/70">Bypassed</p>
                                        <p class="text-2xl font-bold text-success">{{ $healthMetrics['stealth']['bypassed'] }}</p>
                                    </div>
                                    <div>
                                        <p class="text-sm text-base-content/70">Detected</p>
                                        <p class="text-2xl font-bold text-error">{{ $healthMetrics['stealth']['detected'] }}</p>
                                    </div>
                                </div>
                                <p class="text-xs text-base-content/50 mt-2">
                                    Bot detection attempts in last 24 hours
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                @endif
                @endif

                <!-- Statistics Card -->
                @if ($playwrightAvailable && !empty($playwrightStats))
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <h3 class="text-lg font-semibold mb-4">Fetch Method Statistics</h3>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div class="stat">
                                <div class="stat-title">Requires Playwright</div>
                                <div class="stat-value text-primary">{{ $playwrightStats['requires_playwright'] ?? 0 }}</div>
                                <div class="stat-desc">URLs that need JavaScript</div>
                            </div>
                            <div class="stat">
                                <div class="stat-title">Prefers HTTP</div>
                                <div class="stat-value text-secondary">{{ $playwrightStats['prefers_http'] ?? 0 }}</div>
                                <div class="stat-desc">Simple HTTP fetches</div>
                            </div>
                            <div class="stat">
                                <div class="stat-title">Auto-detect</div>
                                <div class="stat-value text-info">{{ $playwrightStats['auto'] ?? 0 }}</div>
                                <div class="stat-desc">Smart routing enabled</div>
                            </div>
                        </div>
                    </div>
                </div>
                @endif

                <!-- Cookie Auto-Refresh Card -->
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <h3 class="text-lg font-semibold mb-4">Cookie Auto-Refresh</h3>
                        @php
                        $autoRefreshEnabled = collect($domains)->where('auto_refresh_enabled', true)->count();
                        $totalDomains = count($domains);
                        @endphp
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="stat">
                                <div class="stat-title">Domains with Auto-Refresh</div>
                                <div class="stat-value text-primary">{{ $autoRefreshEnabled }}</div>
                                <div class="stat-desc">Out of {{ $totalDomains }} total domains</div>
                            </div>
                            <div class="stat">
                                <div class="stat-title">Status</div>
                                <div class="stat-value text-sm">
                                    @if ($autoRefreshEnabled > 0)
                                    <x-badge value="Active" class="badge-success badge-lg" />
                                    @else
                                    <x-badge value="Inactive" class="badge-neutral badge-lg" />
                                    @endif
                                </div>
                                <div class="stat-desc">
                                    @if ($autoRefreshEnabled > 0)
                                    Cookies will refresh automatically
                                    @else
                                    No domains configured
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div class="alert alert-info mt-4">
                            <x-icon name="fas.circle-info" class="w-5 h-5" />
                            <div class="text-sm">
                                <p>Cookie auto-refresh uses Playwright to automatically update cookies before they expire.</p>
                                <p class="mt-1">Enable it per-domain in the <strong>Cookies</strong> tab.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- JavaScript-Required Domains -->
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <h3 class="text-lg font-semibold mb-4">Sites That Need Browser Automation</h3>
                        <p class="text-sm text-base-content/70 mb-4">
                            These sites are always fetched using the browser:
                        </p>
                        <div class="flex flex-wrap gap-2">
                            @php
                            $jsDomains = array_filter(array_map('trim', explode(',', config('services.playwright.js_required_domains', ''))));
                            @endphp
                            @forelse ($jsDomains as $domain)
                            <x-badge value="{{ $domain }}" class="badge-outline" />
                            @empty
                            <p class="text-sm text-base-content/50">No domains configured</p>
                            @endforelse
                        </div>
                        <p class="text-sm text-base-content/50 mt-4">
                            Configure via PLAYWRIGHT_JS_DOMAINS environment variable (comma-separated).
                        </p>
                    </div>
                </div>
            </div>

