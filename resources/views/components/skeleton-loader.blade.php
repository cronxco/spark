@props(['type' => 'card', 'count' => 1, 'class' => ''])

@for ($i = 0; $i < $count; $i++)
    @if ($type === 'card')
        <div {{ $attributes->merge(['class' => 'card bg-base-200 animate-pulse ' . $class]) }}>
            <div class="card-body gap-3">
                <div class="h-4 bg-base-300 rounded w-3/4"></div>
                <div class="h-3 bg-base-300 rounded w-1/2"></div>
                <div class="h-3 bg-base-300 rounded w-2/3"></div>
            </div>
        </div>
    @elseif ($type === 'card-with-image')
        <div {{ $attributes->merge(['class' => 'card bg-base-200 animate-pulse ' . $class]) }}>
            <div class="h-32 bg-base-300 rounded-t-box"></div>
            <div class="card-body gap-3">
                <div class="h-4 bg-base-300 rounded w-3/4"></div>
                <div class="h-3 bg-base-300 rounded w-1/2"></div>
            </div>
        </div>
    @elseif ($type === 'list-item')
        <div {{ $attributes->merge(['class' => 'flex gap-3 p-3 animate-pulse ' . $class]) }}>
            <div class="w-10 h-10 bg-base-300 rounded-full shrink-0"></div>
            <div class="flex-1 space-y-2">
                <div class="h-4 bg-base-300 rounded w-2/3"></div>
                <div class="h-3 bg-base-300 rounded w-1/3"></div>
            </div>
        </div>
    @elseif ($type === 'tags')
        <div {{ $attributes->merge(['class' => 'flex flex-wrap gap-2 animate-pulse ' . $class]) }}>
            <div class="h-6 bg-base-300 rounded-full w-16"></div>
            <div class="h-6 bg-base-300 rounded-full w-20"></div>
            <div class="h-6 bg-base-300 rounded-full w-14"></div>
        </div>
    @elseif ($type === 'text')
        <div {{ $attributes->merge(['class' => 'space-y-2 animate-pulse ' . $class]) }}>
            <div class="h-4 bg-base-300 rounded w-full"></div>
            <div class="h-4 bg-base-300 rounded w-5/6"></div>
            <div class="h-4 bg-base-300 rounded w-4/6"></div>
        </div>
    @elseif ($type === 'stat')
        <div {{ $attributes->merge(['class' => 'animate-pulse ' . $class]) }}>
            <div class="h-3 bg-base-300 rounded w-20 mb-2"></div>
            <div class="h-8 bg-base-300 rounded w-32"></div>
        </div>
    @elseif ($type === 'avatar-row')
        <div {{ $attributes->merge(['class' => 'flex items-center gap-3 animate-pulse ' . $class]) }}>
            <div class="w-12 h-12 bg-base-300 rounded-full shrink-0"></div>
            <div class="flex-1">
                <div class="h-4 bg-base-300 rounded w-32 mb-2"></div>
                <div class="h-3 bg-base-300 rounded w-24"></div>
            </div>
        </div>
    @elseif ($type === 'metadata')
        <div {{ $attributes->merge(['class' => 'space-y-3 animate-pulse ' . $class]) }}>
            @for ($j = 0; $j < 4; $j++)
                <div class="flex justify-between">
                    <div class="h-3 bg-base-300 rounded w-24"></div>
                    <div class="h-3 bg-base-300 rounded w-32"></div>
                </div>
            @endfor
        </div>
    @elseif ($type === 'block-grid')
        <div {{ $attributes->merge(['class' => 'grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 ' . $class]) }}>
            @for ($j = 0; $j < 3; $j++)
                <div class="card bg-base-200 animate-pulse">
                    <div class="card-body gap-3">
                        <div class="flex justify-between">
                            <div class="h-5 bg-base-300 rounded w-20"></div>
                            <div class="h-5 bg-base-300 rounded w-16"></div>
                        </div>
                        <div class="h-4 bg-base-300 rounded w-3/4"></div>
                        <div class="h-3 bg-base-300 rounded w-1/2"></div>
                    </div>
                </div>
            @endfor
        </div>
    @elseif ($type === 'event-list')
        <div {{ $attributes->merge(['class' => 'space-y-3 ' . $class]) }}>
            @for ($j = 0; $j < 3; $j++)
                <div class="card bg-base-200 animate-pulse">
                    <div class="card-body p-4 gap-2">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 bg-base-300 rounded-full shrink-0"></div>
                            <div class="flex-1">
                                <div class="h-4 bg-base-300 rounded w-2/3 mb-2"></div>
                                <div class="h-3 bg-base-300 rounded w-1/3"></div>
                            </div>
                        </div>
                    </div>
                </div>
            @endfor
        </div>
    @elseif ($type === 'relationship-list')
        <div {{ $attributes->merge(['class' => 'space-y-2 ' . $class]) }}>
            @for ($j = 0; $j < 5; $j++)
                <div class="flex items-center gap-2 p-2 animate-pulse">
                    <div class="w-6 h-6 bg-base-300 rounded shrink-0"></div>
                    <div class="h-4 bg-base-300 rounded w-16"></div>
                    <div class="h-4 bg-base-300 rounded flex-1"></div>
                </div>
            @endfor
        </div>
    @endif
@endfor
