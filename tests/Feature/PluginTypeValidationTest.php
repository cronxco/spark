<?php

namespace Tests\Feature;

use App\Integrations\PluginRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Tests\TestCase;

class PluginTypeValidationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function all_plugin_types_are_properly_configured(): void
    {
        $plugins = PluginRegistry::getAllPlugins();
        $errors = [];

        foreach ($plugins as $identifier => $pluginClass) {
            $pluginErrors = $this->validatePluginTypes($pluginClass, $identifier);
            if (! empty($pluginErrors)) {
                $errors[$identifier] = $pluginErrors;
            }
        }

        if (! empty($errors)) {
            $errorMessage = "Found undefined types in plugins:\n";
            foreach ($errors as $plugin => $pluginErrors) {
                $errorMessage .= "\nPlugin: {$plugin}\n";
                foreach ($pluginErrors as $type => $undefinedTypes) {
                    $errorMessage .= "  {$type}: " . implode(', ', $undefinedTypes) . "\n";
                }
            }
            $this->fail($errorMessage);
        }

        $this->assertTrue(true, 'All plugin types are properly configured');
    }

    /**
     * @test
     */
    public function all_configured_types_are_actually_used(): void
    {
        $plugins = PluginRegistry::getAllPlugins();
        $errors = [];

        foreach ($plugins as $identifier => $pluginClass) {
            $pluginErrors = $this->validateUnusedTypes($pluginClass, $identifier);
            if (! empty($pluginErrors)) {
                $errors[$identifier] = $pluginErrors;
            }
        }

        if (! empty($errors)) {
            $errorMessage = "Found unused types in plugin configurations:\n";
            foreach ($errors as $plugin => $pluginErrors) {
                $errorMessage .= "\nPlugin: {$plugin}\n";
                foreach ($pluginErrors as $type => $unusedTypes) {
                    $errorMessage .= "  {$type}: " . implode(', ', $unusedTypes) . "\n";
                }
            }
            $this->fail($errorMessage);
        }

        $this->assertTrue(true, 'All configured types are actually used');
    }

    private function validateUnusedTypes(string $pluginClass, string $identifier): array
    {
        $errors = [];

        // Get configured types from the plugin
        $configuredActionTypes = array_keys($pluginClass::getActionTypes());
        $configuredBlockTypes = array_keys($pluginClass::getBlockTypes());
        $configuredObjectTypes = array_keys($pluginClass::getObjectTypes());

        // Get plugin file path
        $reflection = new ReflectionClass($pluginClass);
        $pluginFilePath = $reflection->getFileName();

        if (! $pluginFilePath) {
            return ['error' => ['Could not locate plugin file']];
        }

        // Read the plugin file content
        $fileContent = File::get($pluginFilePath);

        // Extract used types from the code
        $usedActionTypes = $this->extractUsedActionTypes($fileContent);
        $usedBlockTypes = $this->extractUsedBlockTypes($fileContent);
        $usedObjectTypes = $this->extractUsedObjectTypes($fileContent);

        // Get minimal exclusions for types that are actually used but missed by regex
        $exclusions = $this->getMinimalTypeExclusions($identifier);

        // Check for unused action types
        $unusedActionTypes = array_diff($configuredActionTypes, $usedActionTypes, $exclusions['action_types'] ?? []);
        if (! empty($unusedActionTypes)) {
            $errors['action_types'] = $unusedActionTypes;
        }

        // Check for unused block types
        $unusedBlockTypes = array_diff($configuredBlockTypes, $usedBlockTypes, $exclusions['block_types'] ?? []);
        if (! empty($unusedBlockTypes)) {
            $errors['block_types'] = $unusedBlockTypes;
        }

        // Check for unused object types
        $unusedObjectTypes = array_diff($configuredObjectTypes, $usedObjectTypes, $exclusions['object_types'] ?? []);
        if (! empty($unusedObjectTypes)) {
            $errors['object_types'] = $unusedObjectTypes;
        }

        return $errors;
    }

    private function getMinimalTypeExclusions(string $identifier): array
    {
        // Only exclude types that are actually used in code but missed by regex patterns
        $exclusions = [
            'spotify' => [
                'object_types' => ['track', 'album', 'artist', 'genre'], // These are used in EventObject::updateOrCreate but missed by regex
            ],
        ];

        return $exclusions[$identifier] ?? [];
    }

    private function validatePluginTypes(string $pluginClass, string $identifier): array
    {
        $errors = [];

        // Get configured types from the plugin
        $configuredActionTypes = array_keys($pluginClass::getActionTypes());
        $configuredBlockTypes = array_keys($pluginClass::getBlockTypes());
        $configuredObjectTypes = array_keys($pluginClass::getObjectTypes());

        // Get plugin file path
        $reflection = new ReflectionClass($pluginClass);
        $pluginFilePath = $reflection->getFileName();

        if (! $pluginFilePath) {
            return ['error' => ['Could not locate plugin file']];
        }

        // Read the plugin file content
        $fileContent = File::get($pluginFilePath);

        // Extract used types from the code
        $usedActionTypes = $this->extractUsedActionTypes($fileContent);
        $usedBlockTypes = $this->extractUsedBlockTypes($fileContent);
        $usedObjectTypes = $this->extractUsedObjectTypes($fileContent);

        // Check for undefined action types
        $undefinedActionTypes = array_diff($usedActionTypes, $configuredActionTypes);
        if (! empty($undefinedActionTypes)) {
            $errors['action_types'] = $undefinedActionTypes;
        }

        // Check for undefined block types
        $undefinedBlockTypes = array_diff($usedBlockTypes, $configuredBlockTypes);
        if (! empty($undefinedBlockTypes)) {
            $errors['block_types'] = $undefinedBlockTypes;
        }

        // Check for undefined object types
        $undefinedObjectTypes = array_diff($usedObjectTypes, $configuredObjectTypes);
        if (! empty($undefinedObjectTypes)) {
            $errors['object_types'] = $undefinedObjectTypes;
        }

        return $errors;
    }

    private function extractUsedActionTypes(string $fileContent): array
    {
        $actionTypes = [];

        // Look for 'action' => '...' patterns in Event::create context (more flexible)
        preg_match_all("/Event::create\s*\(\s*\[[^\]]*'action'\s*=>\s*['\"]([^'\"]+)['\"]/", $fileContent, $matches);
        if (! empty($matches[1])) {
            $actionTypes = array_merge($actionTypes, $matches[1]);
        }

        // Look for "action" => "..." patterns in Event::create context (more flexible)
        preg_match_all('/Event::create\s*\(\s*\[[^\]]*"action"\s*=>\s*["\']([^"\']+)["\']/', $fileContent, $matches);
        if (! empty($matches[1])) {
            $actionTypes = array_merge($actionTypes, $matches[1]);
        }

        return array_unique(array_filter($actionTypes));
    }

    private function extractUsedBlockTypes(string $fileContent): array
    {
        $blockTypes = [];

        // Look for 'block_type' => '...' patterns in blocks()->create context (more flexible)
        preg_match_all("/blocks\(\)->create\s*\(\s*\[[^\]]*'block_type'\s*=>\s*['\"]([^'\"]+)['\"]/", $fileContent, $matches);
        if (! empty($matches[1])) {
            $blockTypes = array_merge($blockTypes, $matches[1]);
        }

        // Look for "block_type" => "..." patterns in blocks()->create context (more flexible)
        preg_match_all('/blocks\(\)->create\s*\(\s*\[[^\]]*"block_type"\s*=>\s*["\']([^"\']+)["\']/', $fileContent, $matches);
        if (! empty($matches[1])) {
            $blockTypes = array_merge($blockTypes, $matches[1]);
        }

        return array_unique(array_filter($blockTypes));
    }

    private function extractUsedObjectTypes(string $fileContent): array
    {
        $objectTypes = [];

        // Look for EventObject::create/updateOrCreate patterns with 'type' => '...' (more flexible)
        preg_match_all("/EventObject::(?:create|updateOrCreate)\s*\(\s*\[[^\]]*'type'\s*=>\s*['\"]([^'\"]+)['\"]/", $fileContent, $matches);
        if (! empty($matches[1])) {
            $objectTypes = array_merge($objectTypes, $matches[1]);
        }

        // Look for "type" => "..." patterns in EventObject context (more flexible)
        preg_match_all('/EventObject::(?:create|updateOrCreate)\s*\(\s*\[[^\]]*"type"\s*=>\s*["\']([^"\']+)["\']/', $fileContent, $matches);
        if (! empty($matches[1])) {
            $objectTypes = array_merge($objectTypes, $matches[1]);
        }

        return array_unique(array_filter($objectTypes));
    }
}
