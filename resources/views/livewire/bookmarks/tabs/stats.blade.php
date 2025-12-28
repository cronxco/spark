<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
    <div class="card bg-base-200 shadow">
        <div class="card-body">
            <div class="stat">
                <div class="stat-title">Total URLs</div>
                <div class="stat-value text-primary">{{ $stats['total_urls'] ?? 0 }}</div>
            </div>
        </div>
    </div>
    <div class="card bg-base-200 shadow">
        <div class="card-body">
            <div class="stat">
                <div class="stat-title">Active URLs</div>
                <div class="stat-value text-success">{{ $stats['active_urls'] ?? 0 }}</div>
            </div>
        </div>
    </div>
    <div class="card bg-base-200 shadow">
        <div class="card-body">
            <div class="stat">
                <div class="stat-title">URLs with Errors</div>
                <div class="stat-value text-error">{{ $stats['urls_with_errors'] ?? 0 }}</div>
            </div>
        </div>
    </div>
    <div class="card bg-base-200 shadow">
        <div class="card-body">
            <div class="stat">
                <div class="stat-title">Domains with Cookies</div>
                <div class="stat-value text-info">{{ $stats['domains_with_cookies'] ?? 0 }}</div>
            </div>
        </div>
    </div>
    <div class="card bg-base-200 shadow col-span-1 sm:col-span-2">
        <div class="card-body">
            <div class="stat">
                <div class="stat-title">Next Scheduled Run</div>
                <div class="stat-value text-2xl">
                    @if (isset($stats['next_run']))
                    {{ \Carbon\Carbon::parse($stats['next_run'])->diffForHumans() }}
                    @else
                    Not scheduled
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
