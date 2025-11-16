@php
    use Illuminate\Support\Str;
@endphp

@props([
    'data' => [],
    'level' => 0,
])

@if (is_array($data) && count($data) > 0)
    <div class="{{ $level > 0 ? 'mt-2 pl-4 border-l-2 border-base-300' : '' }} w-full">
        <dl class="space-y-0 w-full">
            @foreach ($data as $k => $v)
                @php
                    $label = is_int($k) ? "[$k]" : Str::title(str_replace('_', ' ', (string) $k));
                @endphp

                @if (is_array($v))
                    @php
                        $isList = array_is_list($v);
                        $count = count($v);
                        $preview = '';
                        if ($isList && $count > 0) {
                            $scalarItems = array_values(array_filter($v, fn($item) => is_scalar($item)));
                            if (count($scalarItems) > 0) {
                                $preview = implode(', ', array_map(fn($item) => (string) $item, array_slice($scalarItems, 0, 3)));
                                if ($count > 3) {
                                    $preview .= ', …';
                                }
                            }
                        } else {
                            $keys = array_map(fn($key) => is_int($key) ? "[$key]" : Str::title(str_replace('_', ' ', (string) $key)), array_keys($v));
                            if (count($keys) > 0) {
                                $preview = implode(', ', array_slice($keys, 0, 3));
                                if ($count > 3) {
                                    $preview .= ', …';
                                }
                            }
                        }
                    @endphp

                    <div class="py-2 border-b border-base-200 last:border-0">
                        <details class="block w-full min-w-0" {{ $level === 0 ? 'open' : '' }}>
                            <summary class="cursor-pointer list-none flex items-center gap-2 min-w-0 hover:bg-base-200/50 -mx-2 px-2 py-1 rounded transition-colors">
                                <div class="flex items-center gap-2 flex-1 min-w-0">
                                    <x-icon name="o-chevron-right" class="w-3 h-3 transition-transform [[open]>&]:rotate-90" />
                                    <span class="text-sm font-medium text-base-content/60">{{ $label }}</span>
                                    <x-badge :value="($isList ? 'List' : 'Object') . ': ' . $count" class="badge-ghost badge-xs" />
                                </div>
                                @if ($preview !== '')
                                    <span class="text-xs text-base-content/40 truncate max-w-[200px]" title="{{ $preview }}">{{ $preview }}</span>
                                @endif
                            </summary>
                            <div class="mt-1 min-w-0">
                                <x-metadata-list :data="$v" :level="$level + 1" />
                            </div>
                        </details>
                    </div>
                @else
                    @php
                        $display = is_bool($v) ? ($v ? 'true' : 'false') : (is_null($v) ? '—' : (is_scalar($v) ? (string) $v : json_encode($v)));
                    @endphp
                    <x-metadata-row :label="$label" :value="$display" />
                @endif
            @endforeach
        </dl>
    </div>
@endif
