<?php

/**
 * Script to fix remaining PHPUnit @test docblock annotations that use single-line format
 */
$testFiles = shell_exec('find tests -name "*.php" -exec grep -l "@test" {} \;');
$files = array_filter(explode("\n", trim($testFiles)));

$convertedCount = 0;
$methodCount = 0;

foreach ($files as $file) {
    if (empty($file)) {
        continue;
    }

    echo "Processing: {$file}\n";

    $content = file_get_contents($file);

    // Add the Test attribute import if not already present
    if (! str_contains($content, 'use PHPUnit\\Framework\\Attributes\\Test;')) {
        // Find the namespace declaration
        $lines = explode("\n", $content);
        $insertIndex = -1;

        foreach ($lines as $i => $line) {
            if (str_starts_with(trim($line), 'namespace ')) {
                $insertIndex = $i + 1;
                break;
            }
        }

        if ($insertIndex > 0) {
            // Find where to insert the import (after other imports)
            $importsEnd = $insertIndex;
            for ($i = $insertIndex; $i < count($lines); $i++) {
                if (str_starts_with(trim($lines[$i]), 'use ') ||
                    str_starts_with(trim($lines[$i]), 'class ') ||
                    trim($lines[$i]) === '') {
                    $importsEnd = $i;
                    if (str_starts_with(trim($lines[$i]), 'class ')) {
                        break;
                    }
                } else {
                    break;
                }
            }

            // Insert the import
            array_splice($lines, $importsEnd, 0, 'use PHPUnit\\Framework\\Attributes\\Test;');
            $content = implode("\n", $lines);
        }
    }

    // Convert single-line @test docblocks to #[Test] attributes
    $originalContent = $content;

    // Pattern to match single-line /** @test */ format
    $pattern = '/(?<indent>\s*)\/\*\*\s*@test\s*\*\/\s*\n\s*(?<visibility>public|protected|private)\s+function\s+(?<method>\w+)\s*\(/m';

    $content = preg_replace_callback($pattern, function ($matches) {
        global $methodCount;
        $methodCount++;

        return $matches['indent'] . '#[Test]' . "\n" . $matches['indent'] . $matches['visibility'] . ' function ' . $matches['method'] . '(';
    }, $content);

    if ($content !== $originalContent) {
        file_put_contents($file, $content);
        $convertedCount++;
        echo "  ✓ Converted methods in {$file}\n";
    } else {
        echo "  - No changes needed in {$file}\n";
    }
}

echo "\nConversion complete!\n";
echo 'Files processed: ' . count($files) . "\n";
echo "Files converted: {$convertedCount}\n";
echo "Methods converted: {$methodCount}\n";
