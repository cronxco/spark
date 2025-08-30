<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class IconValidationTest extends TestCase
{
    private array $validIcons = [];
    private array $foundIcons = [];
    private array $invalidIcons = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadValidIcons();
    }

    /**
     * Test that all icon references in the codebase are valid
     *
     * @test
     */
    public function all_icon_references_are_valid(): void
    {
        $this->scanDirectory('app');
        $this->scanDirectory('resources');

        // Remove duplicates and sort
        $this->foundIcons = array_unique($this->foundIcons);
        sort($this->foundIcons);

        // Check each found icon against valid icons
        foreach ($this->foundIcons as $icon) {
            if (! in_array($icon, $this->validIcons)) {
                $this->invalidIcons[] = $icon;
            }
        }

        // If we found invalid icons, fail the test with details
        if (! empty($this->invalidIcons)) {
            $this->fail(
                "Found invalid icon references:\n" .
                implode("\n", $this->invalidIcons) .
                "\n\nValid icons are:\n" .
                implode("\n", array_slice($this->validIcons, 0, 50)) . // Show first 50 valid icons
                "\n\nTotal valid icons available: " . count($this->validIcons)
            );
        }

        $this->assertTrue(true, 'All icon references are valid');
    }

    /**
     * Test that we can actually load the Heroicons package
     *
     * @test
     */
    public function heroicons_package_is_available(): void
    {
        $this->assertNotEmpty($this->validIcons, 'Heroicons package should provide icons');
        $this->assertGreaterThan(100, count($this->validIcons), 'Should have many icons available');
    }

    /**
     * Test that common icon patterns are found
     *
     * @test
     */
    public function common_icon_patterns_are_detected(): void
    {
        // Create a temporary test file with various icon patterns
        $testContent = '
            <x-icon name="o-heart" />
            @svg("o-fire")
            icon("o-star")
            "icon" => "o-bolt"
            class="icon o-user"
        ';

        $tempFile = tempnam(sys_get_temp_dir(), 'icon_test_');
        File::put($tempFile, $testContent);

        $this->scanFileForIcons($tempFile);

        // Clean up
        File::delete($tempFile);

        $this->assertContains('o-heart', $this->foundIcons);
        $this->assertContains('o-fire', $this->foundIcons);
        $this->assertContains('o-star', $this->foundIcons);
        $this->assertContains('o-bolt', $this->foundIcons);
        $this->assertContains('o-user', $this->foundIcons);
    }

    /**
     * Test that invalid icon patterns are not detected
     *
     * @test
     */
    public function invalid_icon_patterns_are_not_detected(): void
    {
        // Create a temporary test file with invalid patterns
        $testContent = '
            <x-icon name="invalid-icon" />
            "icon" => "not-an-icon"
            class="icon invalid"
        ';

        $tempFile = tempnam(sys_get_temp_dir(), 'icon_test_');
        File::put($tempFile, $testContent);

        $this->scanFileForIcons($tempFile);

        // Clean up
        File::delete($tempFile);

        // Should not contain invalid patterns
        $this->assertNotContains('invalid-icon', $this->foundIcons);
        $this->assertNotContains('not-an-icon', $this->foundIcons);
        $this->assertNotContains('invalid', $this->foundIcons);
    }

    /**
     * Test that we can find icons in actual plugin files
     *
     * @test
     */
    public function can_find_icons_in_plugin_files(): void
    {
        $this->scanDirectory('app/Integrations');

        // Should find some icons from our plugin configurations
        $this->assertNotEmpty($this->foundIcons, 'Should find icons in plugin files');

        // Check that we found some of the icons we know exist
        $knownIcons = ['o-heart', 'o-fire', 'o-star', 'o-bolt', 'o-user'];
        $foundKnownIcons = array_intersect($knownIcons, $this->foundIcons);

        $this->assertNotEmpty($foundKnownIcons, 'Should find some known icons');
    }

    /**
     * Load all valid icons from the Heroicons package
     */
    private function loadValidIcons(): void
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
            $this->validIcons[] = $filename;
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
            // Blade UI Kit icon components: <x-icon name="o-heart" />
            '/<x-icon\s+[^>]*name\s*=\s*["\']([^"\']+)["\'][^>]*>/i',

            // @svg directive: @svg('o-heart')
            '/@svg\s*\(\s*["\']([^"\']+)["\']\s*\)/i',

            // Icon helper function: icon('o-heart')
            '/icon\s*\(\s*["\']([^"\']+)["\']\s*\)/i',

            // Icon in arrays: 'icon' => 'o-heart'
            '/[\'"`]icon[\'"`]\s*=>\s*[\'"`]([^"\']+)[\'"`]/i',

            // Icon in strings: "o-heart" (but not CSS-like classes)
            '/[\'"`]([mso]-[a-zA-Z0-9-]{2,})[\'"`]/i',

            // CSS classes: class="icon o-heart" (but not CSS-like classes)
            '/class\s*=\s*["\'][^"\']*?(?:icon\s+)?([mso]-[a-zA-Z0-9-]{2,})[^"\']*["\']/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                foreach ($matches[1] ?? [] as $match) {
                    // Only consider matches that look like Heroicons (m-, o-, s- prefix)
                    // and exclude CSS-like classes and HTML elements
                    if (preg_match('/^[mso]-[a-zA-Z0-9-]+$/', $match) &&
                        strlen($match) > 3 && // Must be longer than just "o-b" or "s-1"
                        ! preg_match('/^[mso]-[a-z]$/', $match) && // Exclude single letters
                        ! preg_match('/^[mso]-(1|8|accent|center|horizontal|info|lg|px|start|rows-min|control|neutral-950|purple-500)$/', $match) && // Exclude CSS-like classes
                        ! preg_match('/^[mso]-[a-z]+-[0-9]+$/', $match)) { // Exclude color classes like m-neutral-950, m-purple-500
                        $this->foundIcons[] = $match;
                    }
                }
            }
        }
    }
}
