<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ListIcons extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'list:icons';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all icons used across the application with their file locations and friendly names';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Scanning application for icon usage...');

        $iconUsage = $this->scanForIcons();

        if (empty($iconUsage)) {
            $this->warn('No icons found in the application.');

            return;
        }

        $this->displayIconUsage($iconUsage);

        $this->info('Icon scan completed successfully!');
    }

    /**
     * Scan the application for icon usage
     */
    private function scanForIcons(): array
    {
        $iconUsage = [];

        // Scan directories
        $directories = ['app', 'resources'];

        foreach ($directories as $directory) {
            if (! file_exists($directory)) {
                continue;
            }

            $files = $this->getFilesRecursively($directory);

            foreach ($files as $file) {
                $this->scanFileForIcons($file, $iconUsage);
            }
        }

        return $iconUsage;
    }

    /**
     * Get all files recursively from a directory
     */
    private function getFilesRecursively(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        // Exclude the ListIcons command file to prevent recursion
        $excludedFile = app_path('Console/Commands/ListIcons.php');

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                // Skip the ListIcons command file
                if ($file->getRealPath() === $excludedFile) {
                    continue;
                }

                $extension = $file->getExtension();
                if (in_array($extension, ['php', 'blade.php', 'js', 'vue', 'tsx', 'jsx'])) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    /**
     * Scan a single file for icon references
     */
    private function scanFileForIcons(string $filePath, array &$iconUsage): void
    {
        $content = file_get_contents($filePath);

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
            '/[\'"`]([mso]-[a-zA-Z0-9-]{2,})[\'"`]/i',

            // FontAwesome icons in strings: "fas.home", "fab.github", etc.
            '/[\'"`]([f][a-z]{2}\.[a-zA-Z0-9-]+)[\'"`]/i',

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
                        ! preg_match('/^[mso]-(1|8|accent|center|horizontal|info|lg|px|start|rows-min|control|neutral-950|purple-500)$/', $match) && // Exclude CSS-like classes
                        ! preg_match('/^[mso]-[a-z]+-[0-9]+$/', $match)) { // Exclude color classes like m-neutral-950, m-purple-500
                        $this->addIconUsage($iconUsage, $match, $filePath);
                    }

                    // Handle FontAwesome icons (fas., fab., far., fal., fad. prefix)
                    if (preg_match('/^[f][a-z]{2}\.[a-zA-Z0-9-]+$/', $match)) {
                        $this->addIconUsage($iconUsage, $match, $filePath);
                    }
                }
            }
        }
    }

    /**
     * Add icon usage to the collection
     */
    private function addIconUsage(array &$iconUsage, string $icon, string $filePath): void
    {
        if (! isset($iconUsage[$icon])) {
            $iconUsage[$icon] = [
                'friendly_name' => $this->getFriendlyName($icon),
                'files' => [],
            ];
        }

        $relativePath = str_replace(base_path() . '/', '', $filePath);
        if (! in_array($relativePath, $iconUsage[$icon]['files'])) {
            $iconUsage[$icon]['files'][] = $relativePath;
        }
    }

    /**
     * Get friendly name for an icon
     */
    private function getFriendlyName(string $icon): string
    {
        // HeroIcons friendly names
        $heroIconNames = [
            'o-heart' => 'Heart (Outline)',
            'o-fire' => 'Fire (Outline)',
            'o-star' => 'Star (Outline)',
            'o-bolt' => 'Lightning Bolt (Outline)',
            'o-user' => 'User (Outline)',
            'o-home' => 'Home (Outline)',
            'o-cog' => 'Cog (Outline)',
            'o-magnifying-glass' => 'Magnifying Glass (Outline)',
            'o-bell' => 'Bell (Outline)',
            'o-envelope' => 'Envelope (Outline)',
            'o-document-text' => 'Document Text (Outline)',
            'o-check-circle' => 'Check Circle (Outline)',
            'o-x-circle' => 'X Circle (Outline)',
            'o-exclamation-triangle' => 'Exclamation Triangle (Outline)',
            'o-arrow-right' => 'Arrow Right (Outline)',
            'o-arrow-left' => 'Arrow Left (Outline)',
            'o-arrow-up' => 'Arrow Up (Outline)',
            'o-arrow-down' => 'Arrow Down (Outline)',
            'o-plus' => 'Plus (Outline)',
            'o-minus' => 'Minus (Outline)',
            'o-trash' => 'Trash (Outline)',
            'o-pencil' => 'Pencil (Outline)',
            'o-eye' => 'Eye (Outline)',
            'o-eye-slash' => 'Eye Slash (Outline)',
            'o-lock-closed' => 'Lock Closed (Outline)',
            'o-lock-open' => 'Lock Open (Outline)',
            'o-key' => 'Key (Outline)',
            'o-puzzle-piece' => 'Puzzle Piece (Outline)',
            'o-cloud-arrow-down' => 'Cloud Arrow Down (Outline)',
            'o-inbox' => 'Inbox (Outline)',
            'o-information-circle' => 'Information Circle (Outline)',
            'o-chat-bubble-left' => 'Chat Bubble Left (Outline)',
            'o-share' => 'Share (Outline)',
            'o-user-plus' => 'User Plus (Outline)',
            'o-user-minus' => 'User Minus (Outline)',
            'o-user-group' => 'User Group (Outline)',
            'o-play' => 'Play (Outline)',
            'o-stop' => 'Stop (Outline)',
            'o-pause' => 'Pause (Outline)',
            'o-x-mark' => 'X Mark (Outline)',
            'o-check' => 'Check (Outline)',
            'o-globe-alt' => 'Globe Alt (Outline)',
            'o-archive-box' => 'Archive Box (Outline)',
            'o-arrow-right-on-rectangle' => 'Arrow Right On Rectangle (Outline)',
            'o-arrow-left-on-rectangle' => 'Arrow Left On Rectangle (Outline)',
            'o-shopping-cart' => 'Shopping Cart (Outline)',
            'o-arrow-path' => 'Arrow Path (Outline)',
            'o-arrow-down-tray' => 'Arrow Down Tray (Outline)',
            'o-arrow-up-tray' => 'Arrow Up Tray (Outline)',
            'o-bookmark' => 'Bookmark (Outline)',
            'o-chat-bubble-left-ellipsis' => 'Chat Bubble Left Ellipsis (Outline)',
            'o-bell-slash' => 'Bell Slash (Outline)',
            'o-no-symbol' => 'No Symbol (Outline)',
            'o-speaker-x-mark' => 'Speaker X Mark (Outline)',
            'o-speaker-wave' => 'Speaker Wave (Outline)',
            'o-map-pin' => 'Map Pin (Outline)',
            'o-power' => 'Power (Outline)',
            'o-link' => 'Link (Outline)',
            'o-link-slash' => 'Link Slash (Outline)',
            'o-arrow-trending-up' => 'Arrow Trending Up (Outline)',
            'o-arrow-trending-down' => 'Arrow Trending Down (Outline)',
            'o-squares-2x2' => 'Squares 2x2 (Outline)',
            'o-document' => 'Document (Outline)',
            'o-photo' => 'Photo (Outline)',
            'o-video-camera' => 'Video Camera (Outline)',
            'o-musical-note' => 'Musical Note (Outline)',
            'o-calendar' => 'Calendar (Outline)',
            'o-banknotes' => 'Banknotes (Outline)',
            'o-wallet' => 'Wallet (Outline)',
            'o-flag' => 'Flag (Outline)',
            'o-folder' => 'Folder (Outline)',
            'o-users' => 'Users (Outline)',
            'o-building-office' => 'Building Office (Outline)',
            'o-device-phone-mobile' => 'Device Phone Mobile (Outline)',
            'o-computer-desktop' => 'Computer Desktop (Outline)',
            'o-bell-alert' => 'Bell Alert (Outline)',
            'o-chat-bubble-left-right' => 'Chat Bubble Left Right (Outline)',
            'o-device-phone-mobile' => 'Device Phone Mobile (Outline)',
            'o-document-duplicate' => 'Document Duplicate (Outline)',
            'o-plus-circle' => 'Plus Circle (Outline)',
            'o-x-circle' => 'X Circle (Outline)',
            'o-book-open' => 'Book Open (Outline)',
            'o-paper-airplane' => 'Paper Airplane (Outline)',
            'o-inbox' => 'Inbox (Outline)',
            'o-star' => 'Star (Outline)',
            'o-musical-note' => 'Musical Note (Outline)',
            'o-video-camera' => 'Video Camera (Outline)',
            'o-book-open' => 'Book Open (Outline)',
            'o-pencil' => 'Pencil (Outline)',
            'o-paper-airplane' => 'Paper Airplane (Outline)',
            'o-inbox' => 'Inbox (Outline)',
            'o-arrow-down-tray' => 'Arrow Down Tray (Outline)',
            'o-arrow-up-tray' => 'Arrow Up Tray (Outline)',
            'o-bookmark' => 'Bookmark (Outline)',
            'o-chat-bubble-left-ellipsis' => 'Chat Bubble Left Ellipsis (Outline)',
            'o-bell' => 'Bell (Outline)',
            'o-bell-slash' => 'Bell Slash (Outline)',
            'o-no-symbol' => 'No Symbol (Outline)',
            'o-speaker-x-mark' => 'Speaker X Mark (Outline)',
            'o-speaker-wave' => 'Speaker Wave (Outline)',
            'o-map-pin' => 'Map Pin (Outline)',
            'o-power' => 'Power (Outline)',
            'o-link' => 'Link (Outline)',
            'o-link-slash' => 'Link Slash (Outline)',
            'o-arrow-path' => 'Arrow Path (Outline)',
            'o-archive-box' => 'Archive Box (Outline)',
            'o-arrow-down-tray' => 'Arrow Down Tray (Outline)',
            'o-arrow-up-tray' => 'Arrow Up Tray (Outline)',
            'o-arrow-down' => 'Arrow Down (Outline)',
            'o-trash' => 'Trash (Outline)',
            'o-arrow-trending-up' => 'Arrow Trending Up (Outline)',
            'o-arrow-trending-down' => 'Arrow Trending Down (Outline)',
            'o-plus' => 'Plus (Outline)',
            'o-minus' => 'Minus (Outline)',
            'o-arrow-trending-up' => 'Arrow Trending Up (Outline)',
            'o-arrow-trending-down' => 'Arrow Trending Down (Outline)',
        ];

        // FontAwesome friendly names
        $fontAwesomeNames = [
            'fas.home' => 'Home (Solid)',
            'fas.user' => 'User (Solid)',
            'fas.lock' => 'Lock (Solid)',
            'fas.palette' => 'Palette (Solid)',
            'fas.key' => 'Key (Solid)',
            'fas.bolt' => 'Lightning Bolt (Solid)',
            'fas.puzzle-piece' => 'Puzzle Piece (Solid)',
            'fas.pound-sign' => 'Pound Sign (Solid)',
            'fas.cloud-arrow-down' => 'Cloud Arrow Down (Solid)',
            'fas.inbox' => 'Inbox (Solid)',
            'fas.exclamation-triangle' => 'Exclamation Triangle (Solid)',
            'fas.info-circle' => 'Info Circle (Solid)',
            'fab.searchengin' => 'Search Engine (Brand)',
            'fab.github' => 'GitHub (Brand)',
            'far.user' => 'User (Regular)',
        ];

        return $heroIconNames[$icon] ?? $fontAwesomeNames[$icon] ?? $this->formatIconName($icon);
    }

    /**
     * Format icon name for display
     */
    private function formatIconName(string $icon): string
    {
        // Convert kebab-case to Title Case
        $parts = explode('-', str_replace(['o-', 'fas.', 'fab.', 'far.', 'fal.', 'fad.'], '', $icon));
        $formatted = implode(' ', array_map('ucfirst', $parts));

        // Add prefix info
        if (str_starts_with($icon, 'o-')) {
            return $formatted . ' (HeroIcon Outline)';
        }

        if (str_starts_with($icon, 'fas.')) {
            return $formatted . ' (FontAwesome Solid)';
        }

        if (str_starts_with($icon, 'fab.')) {
            return $formatted . ' (FontAwesome Brand)';
        }

        if (str_starts_with($icon, 'far.')) {
            return $formatted . ' (FontAwesome Regular)';
        }

        return $formatted;
    }

    /**
     * Display icon usage in a formatted table
     */
    private function displayIconUsage(array $iconUsage): void
    {
        $this->info("\nIcon Usage Summary:");
        $this->info("==================\n");

        $totalIcons = count($iconUsage);
        $totalFiles = 0;

        foreach ($iconUsage as $icon => $data) {
            $totalFiles += count($data['files']);
        }

        $this->info("Total unique icons found: {$totalIcons}");
        $this->info("Total file references: {$totalFiles}\n");

        // Group by icon type
        $heroIcons = [];
        $fontAwesomeIcons = [];

        foreach ($iconUsage as $icon => $data) {
            if (str_starts_with($icon, 'o-')) {
                $heroIcons[$icon] = $data;
            } elseif (str_starts_with($icon, 'fa')) {
                $fontAwesomeIcons[$icon] = $data;
            }
        }

        // Sort by usage count (most used first)
        $this->sortIconsByUsage($heroIcons);
        $this->sortIconsByUsage($fontAwesomeIcons);

        // Display HeroIcons
        if (! empty($heroIcons)) {
            $heroIconCount = count($heroIcons);
            $this->info("HeroIcons ({$heroIconCount} icons) - Ordered by usage:");
            $this->info("==========================================\n");

            foreach ($heroIcons as $icon => $data) {
                $this->displayIconDetails($icon, $data);
            }
        }

        // Display FontAwesome icons
        if (! empty($fontAwesomeIcons)) {
            $fontAwesomeCount = count($fontAwesomeIcons);
            $this->info("\nFontAwesome Icons ({$fontAwesomeCount} icons) - Ordered by usage:");
            $this->info("==========================================\n");

            foreach ($fontAwesomeIcons as $icon => $data) {
                $this->displayIconDetails($icon, $data);
            }
        }

        // Identify potential duplicates
        $this->identifyDuplicates($iconUsage);
    }

    /**
     * Display details for a single icon
     */
    private function displayIconDetails(string $icon, array $data): void
    {
        $fileCount = count($data['files']);
        $this->line("<fg=yellow>{$icon}</> - {$data['friendly_name']}");
        $this->line("  Files ({$fileCount}):");

        foreach ($data['files'] as $file) {
            $this->line("    <fg=gray>{$file}</>");
        }

        $this->line('');
    }

    /**
     * Sort icons by usage count (most used first)
     */
    private function sortIconsByUsage(array &$icons): void
    {
        uasort($icons, function ($a, $b) {
            $countA = count($a['files']);
            $countB = count($b['files']);

            // Sort by file count descending, then alphabetically
            if ($countA !== $countB) {
                return $countB <=> $countA;
            }

            return $a['friendly_name'] <=> $b['friendly_name'];
        });
    }

    /**
     * Identify potential duplicates and suggest consolidations
     */
    private function identifyDuplicates(array $iconUsage): void
    {
        $this->info("\nDuplicate Analysis:");
        $this->info("==================\n");

        // Find icons used in multiple files
        $multiFileIcons = [];
        foreach ($iconUsage as $icon => $data) {
            $fileCount = count($data['files']);
            if ($fileCount > 1) {
                $multiFileIcons[$icon] = $data;
            }
        }

        if (empty($multiFileIcons)) {
            $this->info('No icons found that are used in multiple files.');

            return;
        }

        // Sort by usage count
        $this->sortIconsByUsage($multiFileIcons);

        $this->info('Icons used in multiple files (consider consolidating):');
        $this->line('');

        foreach ($multiFileIcons as $icon => $data) {
            $fileCount = count($data['files']);
            $this->line("<fg=yellow>{$icon}</> - {$data['friendly_name']} ({$fileCount} files)");

            // Show the files where this icon is used
            foreach ($data['files'] as $file) {
                $this->line("  <fg=gray>• {$file}</>");
            }
            $this->line('');
        }

        // Look for potential semantic duplicates (similar icons)
        $this->identifySemanticDuplicates($iconUsage);
    }

    /**
     * Identify potential semantic duplicates (similar icons that might be consolidated)
     */
    private function identifySemanticDuplicates(array $iconUsage): void
    {
        $this->info('Potential Semantic Duplicates:');
        $this->info("=============================\n");

        // Define groups of similar icons
        $semanticGroups = [
            'User-related' => ['o-user', 'o-user-plus', 'o-user-minus', 'o-user-group', 'o-users'],
            'Arrow-related' => ['o-arrow-up', 'o-arrow-down', 'o-arrow-left', 'o-arrow-right', 'o-arrow-path', 'o-arrow-trending-up', 'o-arrow-trending-down'],
            'Document-related' => ['o-document', 'o-document-text', 'o-document-duplicate'],
            'Chat-related' => ['o-chat-bubble-left', 'o-chat-bubble-left-ellipsis', 'o-chat-bubble-left-right'],
            'Bell-related' => ['o-bell', 'o-bell-slash', 'o-bell-alert'],
            'Eye-related' => ['o-eye', 'o-eye-slash'],
            'Lock-related' => ['o-lock-closed', 'o-lock-open'],
            'Check-related' => ['o-check', 'o-check-circle'],
            'X-related' => ['o-x-mark', 'o-x-circle'],
            'Plus-related' => ['o-plus', 'o-plus-circle'],
            'Trash-related' => ['o-trash'],
            'Pencil-related' => ['o-pencil'],
            'Home-related' => ['o-home', 'fas.home'],
            'Cog-related' => ['o-cog', 'fas.cog'],
            'Key-related' => ['o-key', 'fas.key'],
            'Lock-related' => ['o-lock-closed', 'fas.lock'],
            'User-related' => ['o-user', 'fas.user'],
        ];

        $foundGroups = [];

        foreach ($semanticGroups as $groupName => $iconGroup) {
            $foundIcons = [];
            foreach ($iconGroup as $icon) {
                if (isset($iconUsage[$icon])) {
                    $foundIcons[$icon] = $iconUsage[$icon];
                }
            }

            if (count($foundIcons) > 1) {
                $foundGroups[$groupName] = $foundIcons;
            }
        }

        if (empty($foundGroups)) {
            $this->info('No obvious semantic duplicates found.');

            return;
        }

        foreach ($foundGroups as $groupName => $icons) {
            $this->line("<fg=cyan>{$groupName}:</>");
            foreach ($icons as $icon => $data) {
                $fileCount = count($data['files']);
                $this->line("  <fg=yellow>{$icon}</> - {$data['friendly_name']} ({$fileCount} files)");
            }
            $this->line('');
        }

        $this->info('Suggestions:');
        $this->info('• Consider using consistent icons within each semantic group');
        $this->info('• Create reusable icon components for commonly used icons');
        $this->info('• Standardize icon usage across similar UI elements');
    }
}
