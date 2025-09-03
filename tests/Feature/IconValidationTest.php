<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IconValidationTest extends TestCase
{
    private array $validHeroIcons = [];

    private array $validFontAwesomeIcons = [];

    private array $foundIcons = [];

    private array $invalidIcons = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadValidHeroIcons();
        $this->loadValidFontAwesomeIcons();
    }

    #[Test]
    public function all_icon_references_are_valid(): void
    {
        $this->scanDirectory('app');
        $this->scanDirectory('resources');

        // Remove duplicates and sort
        $this->foundIcons = array_unique($this->foundIcons);
        sort($this->foundIcons);

        // Check each found icon against valid icons
        foreach ($this->foundIcons as $icon) {
            if (! in_array($icon, $this->validHeroIcons) && ! in_array($icon, $this->validFontAwesomeIcons)) {
                $this->invalidIcons[] = $icon;
            }
        }

        // If we found invalid icons, fail the test with details
        if (! empty($this->invalidIcons)) {
            $this->fail(
                "Found invalid icon references:\n" .
                implode("\n", $this->invalidIcons) .
                "\n\nValid HeroIcons are:\n" .
                implode("\n", array_slice($this->validHeroIcons, 0, 50)) . // Show first 50 valid icons
                "\n\nValid FontAwesome icons are:\n" .
                implode("\n", array_slice($this->validFontAwesomeIcons, 0, 50)) . // Show first 50 valid icons
                "\n\nTotal valid HeroIcons available: " . count($this->validHeroIcons) .
                "\nTotal valid FontAwesome icons available: " . count($this->validFontAwesomeIcons)
            );
        }

        $this->assertTrue(true, 'All icon references are valid');
    }

    #[Test]
    public function heroicons_package_is_available(): void
    {
        $this->assertNotEmpty($this->validHeroIcons, 'Heroicons package should provide icons');
        $this->assertGreaterThan(100, count($this->validHeroIcons), 'Should have many icons available');
    }

    #[Test]
    public function fontawesome_package_is_available(): void
    {
        $this->assertNotEmpty($this->validFontAwesomeIcons, 'FontAwesome package should provide icons');
        $this->assertGreaterThan(100, count($this->validFontAwesomeIcons), 'Should have many icons available');
    }

    #[Test]
    public function common_icon_patterns_are_detected(): void
    {
        // Create a temporary test file with various icon patterns
        $testContent = '
            <x-icon name="o-heart" />
            @svg("o-fire")
            icon("o-star")
            "icon" => "o-bolt"
            class="icon o-user"
            <x-icon name="fas.home" />
            <x-icon name="fab.github" />
            <x-icon name="far.user" />
        ';

        $tempFile = tempnam(sys_get_temp_dir(), 'icon_test_');
        File::put($tempFile, $testContent);

        $this->scanFileForIcons($tempFile);

        // Clean up
        File::delete($tempFile);

        // Test HeroIcons
        $this->assertContains('o-heart', $this->foundIcons);
        $this->assertContains('o-fire', $this->foundIcons);
        $this->assertContains('o-star', $this->foundIcons);
        $this->assertContains('o-bolt', $this->foundIcons);
        $this->assertContains('o-user', $this->foundIcons);

        // Test FontAwesome (only available variants)
        $this->assertContains('fas.home', $this->foundIcons);
        $this->assertContains('fab.github', $this->foundIcons);
        $this->assertContains('far.user', $this->foundIcons);
    }

    #[Test]
    public function invalid_icon_patterns_are_not_detected(): void
    {
        // Clear any previously found icons
        $this->foundIcons = [];

        // Create a temporary test file with invalid patterns
        $testContent = '
            <x-icon name="invalid-icon" />
            "icon" => "not-an-icon"
            class="icon invalid"
            <x-icon name="fas.invalid-icon" />
            <x-icon name="fab.not-real" />
        ';

        $tempFile = tempnam(sys_get_temp_dir(), 'icon_test_');
        File::put($tempFile, $testContent);

        $this->scanFileForIcons($tempFile);

        // Clean up
        File::delete($tempFile);

        // Should not contain invalid HeroIcon patterns
        $this->assertNotContains('invalid-icon', $this->foundIcons);
        $this->assertNotContains('not-an-icon', $this->foundIcons);
        $this->assertNotContains('invalid', $this->foundIcons);

        // FontAwesome patterns with invalid icon names should be detected as valid patterns
        // but we can test that they're not in the valid icons list
        $this->assertNotContains('fas.invalid-icon', $this->validFontAwesomeIcons);
        $this->assertNotContains('fab.not-real', $this->validFontAwesomeIcons);
    }

    #[Test]
    public function can_find_icons_in_plugin_files(): void
    {
        $this->scanDirectory('app/Integrations');

        // Should find some icons from our plugin configurations
        $this->assertNotEmpty($this->foundIcons, 'Should find icons in plugin files');

        // Check that we found some of the icons we know exist
        $knownHeroIcons = ['o-heart', 'o-fire', 'o-star', 'o-bolt', 'o-user'];
        $knownFontAwesomeIcons = ['fas.puzzle-piece', 'fas.home', 'fab.github'];
        $foundKnownIcons = array_intersect(array_merge($knownHeroIcons, $knownFontAwesomeIcons), $this->foundIcons);

        $this->assertNotEmpty($foundKnownIcons, 'Should find some known icons');
    }

    /**
     * Load all valid icons from the Heroicons package
     */
    private function loadValidHeroIcons(): void
    {
        $heroiconsPath = 'vendor/blade-ui-kit/blade-heroicons/resources/svg';

        if (! File::exists($heroiconsPath)) {
            $this->markTestSkipped('Heroicons package not found');

            return;
        }

        // Scan all SVG files directly in the svg directory
        $svgFiles = File::glob($heroiconsPath . '/*.svg');

        foreach ($svgFiles as $svgFile) {
            $filename = basename($svgFile, '.svg');
            $this->validHeroIcons[] = $filename;
        }
    }

    /**
     * Load all valid icons from the FontAwesome package
     */
    private function loadValidFontAwesomeIcons(): void
    {
        $fontawesomePath = 'vendor/owenvoke/blade-fontawesome/resources/svg';

        if (! File::exists($fontawesomePath)) {
            $this->markTestSkipped('FontAwesome package not found');

            return;
        }

        // FontAwesome has different categories: brands, regular, solid
        // Note: light (fal) and duotone (fad) variants are not included in this package
        $categories = ['brands', 'regular', 'solid'];

        foreach ($categories as $category) {
            $categoryPath = $fontawesomePath . '/' . $category;

            if (! File::exists($categoryPath)) {
                continue;
            }

            $svgFiles = File::glob($categoryPath . '/*.svg');

            foreach ($svgFiles as $svgFile) {
                $filename = basename($svgFile, '.svg');

                // Map category to prefix
                $prefix = match ($category) {
                    'brands' => 'fab',
                    'regular' => 'far',
                    'solid' => 'fas',
                    default => 'fas'
                };

                $this->validFontAwesomeIcons[] = $prefix . '.' . $filename;
            }
        }
    }

    /**
     * Scan a directory recursively for icon references
     */
    private function scanDirectory(string $directory): void
    {
        if (! File::exists($directory)) {
            return;
        }

        $files = File::allFiles($directory);

        foreach ($files as $file) {
            $extension = $file->getExtension();

            // Only scan relevant file types
            if (in_array($extension, ['php', 'blade.php', 'js', 'vue', 'tsx', 'jsx'])) {
                $this->scanFileForIcons($file->getPathname());
            }
        }
    }

    /**
     * Scan a single file for icon references
     */
    private function scanFileForIcons(string $filePath): void
    {
        $content = File::get($filePath);

        // Look for various icon reference patterns
        $patterns = [
            // Blade UI Kit icon components: <x-icon name="o-heart" /> or <x-icon name="fas.home" />
            '/<x-icon\s+[^>]*name\s*=\s*["\']([^"\']+)["\'][^>]*>/i',

            // @svg directive: @svg('o-heart') or @svg('fas.home')
            '/@svg\s*\(\s*["\']([^"\']+)["\']\s*\)/i',

            // Icon helper function: icon('o-heart') or icon('fas.home')
            '/icon\s*\(\s*["\']([^"\']+)["\']\s*\)/i',

            // Icon in arrays: 'icon' => 'o-heart' or 'icon' => 'fas.home'
            '/[\'"`]icon[\'"`]\s*=>\s*[\'"`]([^"\']+)[\'"`]/i',

            // Icon in strings: "o-heart" or "fas.home" (but not CSS-like classes)
            // Exclude patterns that look like CSS classes (e.g., items-baseline, flex-col, etc.)
            '/[\'"`]([mso]-[a-zA-Z0-9-]{2,})[\'"`]/i',

            // FontAwesome icons in strings: "fas.home", "fab.github", etc.
            // Note: only fas, fab, far are available in this package
            '/[\'"`]((?:fas|fab|far)\.[a-zA-Z0-9-]+)[\'"`]/i',

            // CSS classes: class="icon o-heart" (but not CSS-like classes)
            '/class\s*=\s*["\'][^"\']*?(?:icon\s+)?([mso]-[a-zA-Z0-9-]{2,})[^"\']*["\']/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                foreach ($matches[1] ?? [] as $match) {
                    // Handle HeroIcons (m-, o-, s- prefix)
                    if (preg_match('/^[mso]-[a-zA-Z0-9-]+$/', $match) &&
                        strlen($match) > 3 && // Must be longer than just "o-b" or "s-1"
                        ! preg_match('/^[mso]-[a-z]$/', $match) && // Exclude single letters
                        ! preg_match('/^[mso]-(1|8|accent|center|horizontal|info|lg|px|start|rows-min|control|neutral-950|purple-500|baseline|visible)$/', $match) && // Exclude CSS-like classes
                        ! preg_match('/^[mso]-[a-z]+-[0-9]+$/', $match) && // Exclude color classes like m-neutral-950, m-purple-500
                        ! preg_match('/^[mso]-(col|row|wrap|start|end|top|bottom|left|right|center|middle|auto|none|block|inline|flex|grid|hidden|show|active|disabled|focus|hover|group|peer|first|last|odd|even|visited|checked|default|required|valid|invalid|in-range|out-of-range|placeholder-shown|autofill|read-only|open|closed|loading|loaded|selected|current|target|enabled)$/', $match)) { // Exclude common CSS class patterns
                        $this->foundIcons[] = $match;
                    }

                    // Handle FontAwesome icons (fas., fab., far. prefix)
                    // Note: only fas, fab, far are available in this package
                    if (preg_match('/^(fas|fab|far)\.[a-zA-Z0-9-]+$/', $match)) {
                        $this->foundIcons[] = $match;
                    }
                }
            }
        }
    }
}
