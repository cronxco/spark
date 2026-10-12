<x-layouts.app :title="__('GoCardless Admin')">
    <div>
        <x-header title="GoCardless Admin" separator>
            <x-slot:actions>

            </x-slot:actions>
        </x-header>

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

        @if (isset($error))
            <div class="alert alert-error mb-6">
                <strong>Error:</strong> {{ $error }}
            </div>
        @endif

        <div class="flex flex-col gap-6">
            <!-- Agreements Section -->
            <div class="card bg-base-100 shadow-sm">
                <div class="card-body">
                    <h3 class="card-title">End User Agreements</h3>
                    <p class="text-sm text-base-content/70 mb-4">
                        These are the agreements that have been created with GoCardless for accessing bank data.
                    </p>

                    @if (empty($agreements))
                        <div class="text-center py-8">
                            <p class="text-base-content/50">No agreements found.</p>
                        </div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="table table-zebra">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Institution</th>
                                        <th>Created</th>
                                        <th>Accepted</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($agreements as $agreement)
                                        <tr>
                                            <td class="font-mono text-xs">{{ $agreement['id'] }}</td>
                                            <td>
                                                <div class="font-medium">{{ $agreement['institution_id'] }}</div>
                                                @if (isset($agreement['max_historical_days']))
                                                    <div class="text-sm text-base-content/70">
                                                        Max: {{ $agreement['max_historical_days'] }} days
                                                    </div>
                                                @endif
                                            </td>
                                            <td>
                                                <div class="text-sm">
                                                    {{ \Carbon\Carbon::parse($agreement['created'])->format('M j, Y g:i A') }}
                                                </div>
                                            </td>
                                            <td>
                                                @if (isset($agreement['accepted']))
                                                    <div class="text-sm">
                                                        {{ \Carbon\Carbon::parse($agreement['accepted'])->format('M j, Y g:i A') }}
                                                    </div>
                                                @else
                                                    <span class="badge badge-warning">Pending</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if (isset($agreement['accepted']))
                                                    <span class="badge badge-success">Active</span>
                                                @else
                                                    <span class="badge badge-warning">Pending</span>
                                                @endif
                                            </td>
                                            <td>
                                                <form method="POST" action="{{ route('admin.gocardless.deleteAgreement', $agreement['id']) }}"
                                                      class="inline" onsubmit="return confirm('Are you sure you want to delete this agreement?')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-error">
                                                        Delete
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Requisitions Section -->
            <div class="card bg-base-100 shadow-sm">
                <div class="card-body">
                    <h3 class="card-title">Requisitions</h3>
                    <p class="text-sm text-base-content/70 mb-4">
                        These are the requisitions that have been created to access specific bank accounts.
                    </p>

                    @if (empty($requisitions))
                        <div class="text-center py-8">
                            <p class="text-base-content/50">No requisitions found.</p>
                        </div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="table table-zebra">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Institution</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                        <th>Accounts</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($requisitions as $requisition)
                                        <tr>
                                            <td class="font-mono text-xs">{{ $requisition['id'] }}</td>
                                            <td>
                                                <div class="font-medium">{{ $requisition['institution_id'] }}</div>
                                                @if (isset($requisition['agreement']))
                                                    <div class="text-sm text-base-content/70">
                                                        Agreement: {{ Str::limit($requisition['agreement'], 20) }}
                                                    </div>
                                                @endif
                                            </td>
                                            <td>
                                                @switch($requisition['status'] ?? 'unknown')
                                                    @case('CR')
                                                        <span class="badge badge-info">Created</span>
                                                        @break
                                                    @case('LN')
                                                        <span class="badge badge-warning">Linking</span>
                                                        @break
                                                    @case('EX')
                                                        <span class="badge badge-success">Expired</span>
                                                        @break
                                                    @case('RJ')
                                                        <span class="badge badge-error">Rejected</span>
                                                        @break
                                                    @default
                                                        <span class="badge badge-neutral">{{ $requisition['status'] ?? 'Unknown' }}</span>
                                                @endswitch
                                            </td>
                                            <td>
                                                <div class="text-sm">
                                                    {{ \Carbon\Carbon::parse($requisition['created'])->format('M j, Y g:i A') }}
                                                </div>
                                            </td>
                                            <td>
                                                @if (isset($requisition['accounts']) && is_array($requisition['accounts']))
                                                    <div class="text-sm">
                                                        {{ count($requisition['accounts']) }} account(s)
                                                    </div>
                                                    @if (count($requisition['accounts']) > 0)
                                                        <div class="text-xs text-base-content/70">
                                                            {{ Str::limit(implode(', ', $requisition['accounts']), 30) }}
                                                        </div>
                                                    @endif
                                                @else
                                                    <span class="text-base-content/50">No accounts</span>
                                                @endif
                                            </td>
                                            <td>
                                                <form method="POST" action="{{ route('admin.gocardless.deleteRequisition', $requisition['id']) }}"
                                                      class="inline" onsubmit="return confirm('Are you sure you want to delete this requisition?')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-error">
                                                        Delete
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
