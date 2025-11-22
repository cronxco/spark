<?php

namespace App\Providers;

use BladeUI\Icons\Factory;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class IconServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Override the @svg directive to handle FontAwesome icons via our custom component
        Blade::directive('svg', function ($expression) {
            return "<?php echo app('" . static::class . "')->renderSvg({$expression}); ?>";
        });
    }

    /**
     * Render an SVG icon, routing FontAwesome icons through our custom handling.
     */
    public function renderSvg(string $name, string|array $class = ''): string
    {
        // Normalize class parameter
        if (is_array($class)) {
            $class = implode(' ', $class);
        }

        // Handle legacy FontAwesome format: fas.icon-name -> fas-icon-name
        if (str_contains($name, '.')) {
            $parts = explode('.', $name, 2);
            $name = $parts[0] . '-' . $parts[1];
        }

        // Use the icon_name() helper to normalize icon names based on default library
        $name = icon_name($name);

        // Handle bare names (no prefix) - add default prefix based on library
        if (! preg_match('/^(fa[srbldt]|[os]|heroicon)-/', $name)) {
            $defaultLibrary = config('icons.default_library', 'fontawesome');
            $name = $defaultLibrary === 'fontawesome' ? 'fas-' . $name : 'o-' . $name;
        }

        // Check if this is a FontAwesome icon
        if (preg_match('/^fa[srbldt]-/', $name)) {
            // Use blade-fontawesome's svg helper directly
            // The blade-fontawesome package registers components like <x-fas-icon>
            // We can render it as a component
            return view('components.icon', ['name' => $name, 'size' => $class])->render();
        }

        // For heroicons and other icons, use the default blade-icons factory
        /** @var Factory $factory */
        $factory = app(Factory::class);

        return $factory->svg($name, $class)->toHtml();
    }
}
