@props(['active'])

@php
    $tabs = [
        'events' => ['Events', 'fas.list', 'admin.events.index'],
        'objects' => ['Objects', 'fas.cube', 'admin.objects.index'],
        'blocks' => ['Blocks', 'fas.cubes', 'admin.blocks.index'],
        'relationships' => ['Relationships', 'fas.link', 'admin.relationships.index'],
    ];
@endphp

<div role="tablist" class="tabs tabs-bordered mb-6">
    @foreach ($tabs as $key => [$label, $icon, $route])
        <a role="tab" href="{{ route($route) }}" wire:navigate
            class="tab gap-2 {{ $active === $key ? 'tab-active font-semibold' : '' }}">
            <x-icon :name="$icon" class="w-4 h-4" />
            {{ $label }}
        </a>
    @endforeach
</div>
