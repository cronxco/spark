<x-layouts.app :title="__('Migrations Admin')">

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="alert alert-success mb-6">
                    {{ session('success') }}
                </div>
            @endif

            @if (session('error'))
                <div class="alert alert-error mb-6">
                    {{ session('error') }}
                </div>
            @endif

            <!-- Oura Value Mapping Migration Section -->
            <div class="card mb-8">
                <div class="card-body">
                    <h3 class="card-title">Oura Value Mapping Migration</h3>
                    <p class="text-sm text-base-content/70 mb-4">
                        Migrate existing Oura events to use the new value mapping system for stress and resilience levels.
                        This will update events to store mapped numeric values instead of raw text values.
                    </p>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- All Integrations -->
                        <div class="card bg-base-200">
                            <div class="card-body">
                                <h4 class="font-semibold mb-2">Migrate All Oura Integrations</h4>
                                <p class="text-sm text-base-content/70 mb-4">
                                    Process all Oura integrations at once. This may take longer but ensures consistency across all users.
                                </p>
                                <form method="POST" action="{{ route('admin.migrations.oura') }}" class="inline">
                                    @csrf
                                    <button type="submit" class="btn btn-primary"
                                            onclick="return confirm('Are you sure you want to migrate all Oura integrations? This may take several minutes.')">
                                        <x-icon name="o-arrow-path" class="w-4 h-4" />
                                        Migrate All
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- Specific Integration -->
                        <div class="card bg-base-200">
                            <div class="card-body">
                                <h4 class="font-semibold mb-2">Migrate Specific Integration</h4>
                                <p class="text-sm text-base-content/70 mb-4">
                                    Select a specific Oura integration to migrate. Useful for testing or targeted updates.
                                </p>

                                @if ($ouraIntegrations->isEmpty())
                                    <p class="text-sm text-base-content/50">No Oura integrations found.</p>
                                @else
                                    <form method="POST" action="{{ route('admin.migrations.oura') }}" class="space-y-4">
                                        @csrf
                                        <div>
                                            <label for="integration_id" class="label">
                                                <span class="label-text">Select Integration</span>
                                            </label>
                                            <select name="integration_id" id="integration_id" class="select select-bordered w-full" required>
                                                <option value="">Choose an integration...</option>
                                                @foreach ($ouraIntegrations as $integration)
                                                    <option value="{{ $integration->id }}">
                                                        {{ $integration->name }} ({{ $integration->user->name }})
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <button type="submit" class="btn btn-secondary">
                                            <x-icon name="o-arrow-path" class="w-4 h-4" />
                                            Migrate Selected
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </div>

                    <!-- Integration List -->
                    @if ($ouraIntegrations->isNotEmpty())
                        <div class="mt-6">
                            <h4 class="font-semibold mb-3">Available Oura Integrations</h4>
                            <div class="overflow-x-auto">
                                <table class="table table-zebra">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>User</th>
                                            <th>Instance Type</th>
                                            <th>Created</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($ouraIntegrations as $integration)
                                            <tr>
                                                <td>{{ $integration->name }}</td>
                                                <td>{{ $integration->user->name }}</td>
                                                <td>
                                                    <span class="badge badge-outline">
                                                        {{ $integration->instance_type ?? 'default' }}
                                                    </span>
                                                </td>
                                                <td>{{ $integration->created_at->format('M j, Y') }}</td>
                                                <td>
                                                    @if ($integration->group && $integration->group->access_token)
                                                        <span class="badge badge-success">Active</span>
                                                    @else
                                                        <span class="badge badge-error">Inactive</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Migration Information -->
            <div class="card">
                <div class="card-body">
                    <h3 class="card-title">Migration Information</h3>
                    <div class="space-y-4">
                        <div>
                            <h4 class="font-semibold">What this migration does:</h4>
                            <ul class="list-disc list-inside text-sm text-base-content/70 space-y-1">
                                <li>Updates existing Oura stress and resilience events to use numeric mappings</li>
                                <li>Converts text values like "stressful" to numeric values like 3</li>
                                <li>Preserves original values in event metadata for reference</li>
                                <li>Enables proper timeline display of mapped values</li>
                            </ul>
                        </div>

                        <div>
                            <h4 class="font-semibold">Affected Events:</h4>
                            <ul class="list-disc list-inside text-sm text-base-content/70 space-y-1">
                                <li><code>had_stress_level</code> - Maps day_summary values (stressful, normal, restful)</li>
                                <li><code>had_resilience_level</code> - Maps level values (excellent, solid, adequate, limited, poor)</li>
                            </ul>
                        </div>

                        <div class="alert alert-info">
                            <x-icon name="o-information-circle" class="w-5 h-5" />
                            <div>
                                <h4 class="font-semibold">Note:</h4>
                                <p class="text-sm">This migration is safe to run multiple times. It will only update events that haven't been migrated yet.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</x-layouts.app>
