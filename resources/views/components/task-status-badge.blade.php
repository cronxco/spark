@props(['status'])

@php
$statusMeta = [
    'success' => ['label' => 'Success', 'badge' => 'badge-success', 'icon' => 'fas.circle-check'],
    'failed' => ['label' => 'Failed', 'badge' => 'badge-error', 'icon' => 'fas.circle-xmark'],
    'running' => ['label' => 'Running', 'badge' => 'badge-warning', 'icon' => 'fas.spinner'],
    'pending' => ['label' => 'Pending', 'badge' => 'badge-info', 'icon' => 'fas.clock'],
    'waiting' => ['label' => 'Waiting', 'badge' => 'badge-info badge-outline', 'icon' => 'fas.hourglass-half'],
    'blocked' => ['label' => 'Blocked', 'badge' => 'badge-error badge-outline', 'icon' => 'fas.ban'],
    'not_applicable' => ['label' => 'N/A', 'badge' => 'badge-ghost', 'icon' => 'fas.minus-circle'],
];

$meta = $statusMeta[$status] ?? ['label' => ucfirst($status ?? 'Unknown'), 'badge' => 'badge-ghost', 'icon' => 'fas.question'];
@endphp

<x-badge class="badge-sm gap-1 {{ $meta['badge'] }}">
    <x-icon :name="$meta['icon']" class="w-3 h-3 {{ $status === 'running' ? 'animate-spin' : '' }}" />
    {{ $meta['label'] }}
</x-badge>
