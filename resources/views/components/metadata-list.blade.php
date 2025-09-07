@php
    use Illuminate\Support\Str;
@endphp

@props([
    'data' => [],
    'level' => 0,
])

@if (is_array($data) && count($data) > 0)
    <div class="{{ $level > 0 ? 'mt-1 border-l border-base-300 pl-2' : '' }} w-full">
        <div class="space-y-1 w-full">
            @foreach ($data as $k => $v)
                @php
                    $label = is_int($k) ? $k : Str::title(str_replace('_', ' ', (string) $k));
                    $pillPalette = ['badge-ghost', 'badge-secondary', 'badge-accent', 'badge-info', 'badge-success', 'badge-warning', 'badge-neutral'];
                    $pillClass = $pillPalette[min($level, count($pillPalette) - 1)];
                @endphp

                @if (is_array($v))
                    @php
                        $isList = array_is_list($v);
                        $count = count($v);
                        $preview = '';
                        if ($isList) {
                            $scalarItems = array_values(array_filter($v, fn($item) => is_scalar($item)));
                            if (count($scalarItems) > 0) {
                                $preview = implode(', ', array_map(fn($item) => (string) $item, array_slice($scalarItems, 0, 3)));
                                if ($count > 3) {
                                    $preview .= ', …';
                                }
                            }
                        } else {
                            $keys = array_map(fn($key) => Str::title(str_replace('_', ' ', (string) $key)), array_keys($v));
                            if (count($keys) > 0) {
                                $preview = implode(', ', array_slice($keys, 0, 3));
                                if ($count > 3) {
                                    $preview .= ', …';
                                }
                            }
                        }
                    @endphp

                    <details class="block w-full min-w-0">
                        <summary class="cursor-pointer list-none flex items-center gap-2 min-w-0 text-xs text-base-content/70 hover:text-base-content py-0 my-0">
                            <x-badge :value="$label" class="{{ $pillClass }} badge-xs font-mono" />
                            <span>[{{ $isList ? 'List' : 'Object' }}: {{ $count }}]</span>
                            @if ($preview !== '')
                                <span class="text-[11px] text-base-content/60 truncate flex-1 min-w-0" title="{{ $preview }}">{{ $preview }}</span>
                            @endif
                            <x-icon name="o-chevron-down" class="w-3 h-3" />
                        </summary>
                        <div class="mt-1 min-w-0">
                            <x-metadata-list :data="$v" :level="$level + 1" />
                        </div>
                    </details>
                @else
                    @php
                        $display = is_bool($v) ? ($v ? 'true' : 'false') : (is_null($v) ? '—' : (is_scalar($v) ? (string) $v : json_encode($v)));
                    @endphp
                    <div class="flex items-baseline gap-2 min-w-0 w-full">
                        <x-badge :value="$label" class="{{ $pillClass }} badge-xs font-mono" />
                        <span class="text-sm leading-6 truncate flex-1 min-w-0" title="{{ $display }}">{{ $display }}</span>
                    </div>
                @endif
            @endforeach
        </div>
    </div>
@endif


