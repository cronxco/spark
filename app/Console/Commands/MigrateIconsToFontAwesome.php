<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class MigrateIconsToFontAwesome extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'icons:migrate-to-fontawesome
                            {--dry-run : Preview changes without modifying files}
                            {--path= : Specific path to scan (default: app,resources)}
                            {--details : Show detailed changes for each file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate Heroicon references to FontAwesome equivalents';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $showDetails = $this->option('details');
        $paths = $this->option('path')
            ? [$this->option('path')]
            : ['app', 'resources'];

        $this->info('Loading icon mappings from config/icons.php...');
        $mappings = config('icons.heroicon_to_fontawesome_map', []);

        if (empty($mappings)) {
            $this->error('No icon mappings found in config/icons.php');
            $this->info('Run: php artisan config:cache to refresh config');

            return 1;
        }

        $this->info('Found ' . count($mappings) . ' icon mappings');
        $this->newLine();

        $changes = [];
        $totalReplacements = 0;

        foreach ($paths as $basePath) {
            if (! File::isDirectory($basePath)) {
                $this->warn("Directory not found: {$basePath}");

                continue;
            }

            $this->info("Scanning {$basePath}...");
            $files = $this->getPhpAndBladeFiles($basePath);

            $progressBar = $this->output->createProgressBar(count($files));
            $progressBar->start();

            foreach ($files as $file) {
                $result = $this->processFile($file, $mappings, $dryRun);

                if ($result['count'] > 0) {
                    $relativePath = str_replace(base_path() . '/', '', $file);
                    $changes[$relativePath] = $result;
                    $totalReplacements += $result['count'];
                }

                $progressBar->advance();
            }

            $progressBar->finish();
            $this->newLine(2);
        }

        // Display results
        if (empty($changes)) {
            $this->info('No Heroicon references found to migrate.');

            return 0;
        }

        $this->displayResults($changes, $showDetails);

        $this->newLine();
        $this->info("Summary: {$totalReplacements} icon references in " . count($changes) . ' files');
        $this->newLine();

        if ($dryRun) {
            $this->warn('DRY RUN: No files were modified.');
            $this->info('Run without --dry-run to apply changes.');
        } else {
            $this->info('Migration complete! All icon references have been updated.');
        }

        return 0;
    }

    /**
     * Get all PHP and Blade files from a directory
     */
    private function getPhpAndBladeFiles(string $path): array
    {
        return collect(File::allFiles($path))
            ->filter(function ($file) {
                $extension = $file->getExtension();
                $filename = $file->getFilename();

                // Include .php and .blade.php files
                return $extension === 'php' ||
                       str_ends_with($filename, '.blade.php');
            })
            ->map(fn ($file) => $file->getPathname())
            ->toArray();
    }

    /**
     * Process a single file and replace icons
     */
    private function processFile(string $file, array $mappings, bool $dryRun): array
    {
        $content = File::get($file);
        $originalContent = $content;
        $replacements = [];

        foreach ($mappings as $heroicon => $fontawesome) {
            // Pattern 1: heroicon names in quoted strings (e.g., o-heart)
            $pattern = "/(['\"])(" . preg_quote($heroicon, '/') . ")(['\"])/";
            $replacement = '$1' . $fontawesome . '$3';

            $count = 0;
            $content = preg_replace($pattern, $replacement, $content, -1, $count);

            if ($count > 0) {
                $replacements[$heroicon] = [
                    'to' => $fontawesome,
                    'count' => $count,
                ];
            }
        }

        $totalCount = array_sum(array_column($replacements, 'count'));

        if ($totalCount > 0 && ! $dryRun) {
            File::put($file, $content);
        }

        return [
            'count' => $totalCount,
            'replacements' => $replacements,
        ];
    }

    /**
     * Display the results in a formatted way
     */
    private function displayResults(array $changes, bool $showDetails): void
    {
        $this->info('Files with icon changes:');
        $this->newLine();

        // Sort by number of changes (descending)
        uasort($changes, fn ($a, $b) => $b['count'] <=> $a['count']);

        foreach ($changes as $file => $result) {
            $this->line("<fg=yellow>{$file}</> ({$result['count']} changes)");

            if ($showDetails && ! empty($result['replacements'])) {
                foreach ($result['replacements'] as $from => $details) {
                    $this->line("  <fg=gray>  {$from} -> {$details['to']} ({$details['count']}x)</>");
                }
            }
        }
    }
}
