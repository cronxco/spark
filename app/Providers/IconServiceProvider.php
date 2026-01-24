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
        // Override the @svg directive AFTER all other providers have booted
        // This ensures we override blade-icons' directive registration
        $this->app->booted(function () {
            Blade::directive('svg', function ($expression) {
                return "<?php echo app('".static::class."')->renderSvg({$expression}); ?>";
            });
        });
    }

    /**
     * Render an SVG icon, routing FontAwesome icons through blade-fontawesome components.
     */
    public function renderSvg(string $name, string|array $class = ''): string
    {
        // Normalize class parameter
        if (is_array($class)) {
            $class = implode(' ', $class);
        }

        // Use the icon_name() helper to normalize icon names based on default library
        // This handles heroicon-to-fontawesome conversion when fontawesome is the default
        $name = icon_name($name);

        // Convert FontAwesome dot notation to dash notation for component rendering
        // e.g., fas.bars -> fas-bars (required by blade-fontawesome)
        if (str_contains($name, '.') && preg_match('/^fa[srbldt]\./', $name)) {
            $parts = explode('.', $name, 2);
            $name = $parts[0].'-'.$parts[1];
        }

        // Handle bare names (no prefix) - add default prefix based on library
        if (! preg_match('/^(fa[srbldt][\.-]|[os]-)/', $name)) {
            $defaultLibrary = config('icons.default_library', 'fontawesome');
            $name = $defaultLibrary === 'fontawesome' ? 'fas-'.$name : 'o-'.$name;
        }

        // Check if this is a FontAwesome icon
        if (preg_match('/^fa[srbldt]-/', $name)) {
            // Render FontAwesome icons using Blade component syntax
            // This bypasses blade-icons factory and uses blade-fontawesome components directly
            // e.g., fas-bars becomes <x-fas-bars class="..." />
            $classAttr = $class ? ' class="'.e($class).'"' : '';

            return Blade::render('<x-'.$name.$classAttr.' />');
        }

        // For heroicons and other icons, use the default blade-icons factory
        /** @var Factory $factory */
        $factory = app(Factory::class);

        return $factory->svg($name, $class)->toHtml();
    }
}
