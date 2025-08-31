@php
    use Illuminate\Support\Str;
@endphp

@props([
    'data' => [],
    'level' => 0,
])

@if (is_array($data) && count($data) > 0)
    <div class="space-y-2 {{ $level > 0 ? 'mt-2 pl-3 border-l border-base-300' : '' }}">
        @foreach ($data as $k => $v)
            @php
                $label = is_int($k) ? $k : Str::title(str_replace('_', ' ', (string) $k));
            @endphp

            @if (is_array($v))
                <x-collapse>
                    <x-slot:heading>
                        <x-badge class="badge-ghost badge-sm">{{ $label }}</x-badge>
                        <span class="text-xs text-base-content/60 ml-2">{{ array_is_list($v) ? 'List' : 'Object' }} • {{ count($v) }}</span>
                    </x-slot:heading>
                    <x-slot:content>
                        <x-metadata-list :data="$v" :level="$level + 1" />
                    </x-slot:content>
                </x-collapse>
            @else
                <div class="flex items-start gap-2">
                    <x-badge class="badge-ghost badge-sm">{{ $label }}</x-badge>
                    <div class="text-sm break-words">
                        {{ is_bool($v) ? ($v ? 'true' : 'false') : (is_null($v) ? '—' : (is_scalar($v) ? $v : json_encode($v))) }}
                    </div>
                </div>
            @endif
        @endforeach
    </div>
@endif


