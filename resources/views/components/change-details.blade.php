@php
    use Illuminate\Support\Str;
@endphp

@props([
    'new' => [],
    'old' => [],
    'level' => 0,
])

@php
    $newData = is_array($new ?? null) ? $new : [];
    $oldData = is_array($old ?? null) ? $old : [];
    $keys = collect(array_unique(array_merge(array_keys($oldData), array_keys($newData))))
        ->reject(fn($k) => $k === 'updated_at')
        ->values();
    $changedKeys = $keys->filter(fn($k) => json_encode($oldData[$k] ?? null) !== json_encode($newData[$k] ?? null));
    $changesCount = $changedKeys->count();
    $collapseThreshold = 3;
    $shouldCollapse = $changesCount > $collapseThreshold;
@endphp

@if ($changesCount > 0)
    <div class="{{ $level > 0 ? 'mt-2 pl-3 border-l border-base-300' : '' }}">
        @if ($shouldCollapse)
            <details class="bg-transparent p-0">
                <summary class="cursor-pointer list-none text-sm text-base-content/80 hover:text-base-content">Show changes ({{ $changesCount }})</summary>
                <div class="mt-1">
                    <div class="grid grid-cols-[max-content,1fr] gap-x-3 gap-y-1">
                        @foreach ($changedKeys as $k)
                            @php
                                $label = is_int($k) ? $k : Str::title(str_replace('_', ' ', (string) $k));
                                $before = $oldData[$k] ?? null;
                                $after = $newData[$k] ?? null;
                                $beforeIsComplex = is_array($before) || is_object($before);
                                $afterIsComplex = is_array($after) || is_object($after);
                            @endphp
                            <div class="text-xs text-base-content/60 leading-6">{{ $label }}</div>
                            <div>
                                @if (! $beforeIsComplex && ! $afterIsComplex)
                                    <div class="flex items-center gap-2 text-sm break-words">
                                        <span class="line-through text-error/80">{{ is_bool($before) ? ($before ? 'true' : 'false') : (is_null($before) ? '—' : (is_scalar($before) ? $before : json_encode($before))) }}</span>
                                        <x-icon name="o-arrow-long-right" class="w-4 h-4 text-base-content/40" />
                                        <span class="font-medium text-success">{{ is_bool($after) ? ($after ? 'true' : 'false') : (is_null($after) ? '—' : (is_scalar($after) ? $after : json_encode($after))) }}</span>
                                    </div>
                                @else
                                    <details class="bg-transparent p-0">
                                        <summary class="cursor-pointer list-none text-sm text-base-content/80 hover:text-base-content flex items-center gap-2">
                                            <span>Changed</span>
                                            <x-icon name="o-arrow-long-right" class="w-4 h-4 text-base-content/40" />
                                            <span>{{ (is_array($after) ? (array_is_list($after) ? 'List' : 'Object') : 'Value') }}</span>
                                        </summary>
                                        <div class="mt-1">
                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                                <div>
                                                    <div class="text-xs text-error/80 mb-1">Before</div>
                                                    @if ($beforeIsComplex)
                                                        <x-metadata-list :data="$before" :level="$level + 1" />
                                                    @else
                                                        <div class="text-sm break-words text-error/80">{{ is_bool($before) ? ($before ? 'true' : 'false') : (is_null($before) ? '—' : (is_scalar($before) ? $before : json_encode($before))) }}</div>
                                                    @endif
                                                </div>
                                                <div>
                                                    <div class="text-xs text-success mb-1">After</div>
                                                    @if ($afterIsComplex)
                                                        <x-metadata-list :data="$after" :level="$level + 1" />
                                                    @else
                                                        <div class="text-sm break-words font-medium text-success">{{ is_bool($after) ? ($after ? 'true' : 'false') : (is_null($after) ? '—' : (is_scalar($after) ? $after : json_encode($after))) }}</div>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </details>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </details>
        @else
            <div class="grid grid-cols-[max-content,1fr] gap-x-3 gap-y-1">
                @foreach ($changedKeys as $k)
                    @php
                        $label = is_int($k) ? $k : Str::title(str_replace('_', ' ', (string) $k));
                        $before = $oldData[$k] ?? null;
                        $after = $newData[$k] ?? null;
                        $beforeIsComplex = is_array($before) || is_object($before);
                        $afterIsComplex = is_array($after) || is_object($after);
                    @endphp
                    <div class="text-xs text-base-content/60 leading-6">{{ $label }}</div>
                    <div>
                        @if (! $beforeIsComplex && ! $afterIsComplex)
                            <div class="flex items-center gap-2 text-sm break-words">
                                <span class="line-through text-error/80">{{ is_bool($before) ? ($before ? 'true' : 'false') : (is_null($before) ? '—' : (is_scalar($before) ? $before : json_encode($before))) }}</span>
                                <x-icon name="o-arrow-long-right" class="w-4 h-4 text-base-content/40" />
                                <span class="font-medium text-success">{{ is_bool($after) ? ($after ? 'true' : 'false') : (is_null($after) ? '—' : (is_scalar($after) ? $after : json_encode($after))) }}</span>
                            </div>
                        @else
                            <details class="bg-transparent p-0">
                                <summary class="cursor-pointer list-none text-sm text-base-content/80 hover:text-base-content flex items-center gap-2">
                                    <span>Changed</span>
                                    <x-icon name="o-arrow-long-right" class="w-4 h-4 text-base-content/40" />
                                    <span>{{ (is_array($after) ? (array_is_list($after) ? 'List' : 'Object') : 'Value') }}</span>
                                </summary>
                                <div class="mt-1">
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                        <div>
                                            <div class="text-xs text-error/80 mb-1">Before</div>
                                            @if ($beforeIsComplex)
                                                <x-metadata-list :data="$before" :level="$level + 1" />
                                            @else
                                                <div class="text-sm break-words text-error/80">{{ is_bool($before) ? ($before ? 'true' : 'false') : (is_null($before) ? '—' : (is_scalar($before) ? $before : json_encode($before))) }}</div>
                                            @endif
                                        </div>
                                        <div>
                                            <div class="text-xs text-success mb-1">After</div>
                                            @if ($afterIsComplex)
                                                <x-metadata-list :data="$after" :level="$level + 1" />
                                            @else
                                                <div class="text-sm break-words font-medium text-success">{{ is_bool($after) ? ($after ? 'true' : 'false') : (is_null($after) ? '—' : (is_scalar($after) ? $after : json_encode($after))) }}</div>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </details>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@endif


