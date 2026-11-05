@props([
    'name' => null,
    'size' => 'w-5 h-5',
])

@if ($name)
    @php
        $iconClass = $attributes->merge(['class' => $size])->get('class');
        $svgName = $name;

        // Use the icon_name() helper to normalize icon names based on default library
        // This handles heroicon-to-fontawesome conversion when fontawesome is the default
        $svgName = icon_name($svgName);

        // Convert FontAwesome dot notation to dash notation for component rendering
        // e.g., fas.bars -> fas-bars (required by blade-fontawesome)
        if (str_contains($svgName, '.') && preg_match('/^fa[srbldt]\./', $svgName)) {
            $parts = explode('.', $svgName, 2);
            $svgName = $parts[0] . '-' . $parts[1];
        }

        // Handle bare names (no prefix) - add default prefix based on library
        if (!preg_match('/^(fa[srbldt][\.-]|[os]-)/', $svgName)) {
            $defaultLibrary = config('icons.default_library', 'fontawesome');
            $svgName = $defaultLibrary === 'fontawesome' ? 'fas-' . $svgName : 'o-' . $svgName;
        }

        // Determine if this is a FontAwesome or Heroicon
        $isFontAwesome = preg_match('/^fa[srbldt]-/', $svgName);
    @endphp

    @if ($isFontAwesome)
        {{-- Use blade-fontawesome component syntax for FontAwesome icons --}}
        <x-dynamic-component :component="$svgName" :class="$iconClass" />
    @else
        {{-- Use @svg() for Heroicons (which works with blade-heroicons) --}}
        @svg($svgName, $iconClass)
    @endif
@endif
