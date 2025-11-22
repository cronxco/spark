@props([
    'name' => null,
    'size' => 'w-5 h-5',
])

@if ($name)
    @php
        $iconClass = $attributes->merge(['class' => $size])->get('class');

        // Determine icon library and normalize name
        $library = 'heroicons'; // Default fallback
        $svgName = $name;

        if (str_starts_with($name, 'fas-') || str_starts_with($name, 'far-') || str_starts_with($name, 'fab-')) {
            // FontAwesome format: fas-icon-name, far-icon-name, fab-icon-name
            $library = 'fontawesome';
            $svgName = $name;
        } elseif (str_contains($name, '.')) {
            // Legacy FontAwesome format: fas.icon-name -> fas-icon-name
            $library = 'fontawesome';
            $parts = explode('.', $name, 2);
            $svgName = $parts[0] . '-' . $parts[1];
        } elseif (str_starts_with($name, 'o-') || str_starts_with($name, 's-')) {
            // Heroicons format
            $library = 'heroicons';
            $svgName = $name;
        } elseif (config('icons.default_library', 'heroicons') === 'fontawesome') {
            // Bare name with FontAwesome as default -> fas-icon-name
            $library = 'fontawesome';
            $svgName = 'fas-' . $name;
        } else {
            // Bare name with Heroicons as default -> o-icon-name
            $library = 'heroicons';
            $svgName = 'o-' . $name;
        }
    @endphp

    @if ($library === 'fontawesome')
        @svg($svgName, $iconClass)
    @else
        @svg($svgName, $iconClass)
    @endif
@endif
