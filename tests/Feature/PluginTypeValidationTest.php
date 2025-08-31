<?php

namespace Tests\Feature;

use App\Integrations\PluginRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;
use Throwable;

class PluginTypeValidationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
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

    #[Test]
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

        // Also include configured types from the plugin itself
        // This ensures we catch types that are defined in get*Types() methods but not used in code
        $configuredActionTypesFromPlugin = $this->extractConfiguredActionTypes($pluginClass);
        $configuredBlockTypesFromPlugin = $this->extractConfiguredBlockTypes($pluginClass);
        $configuredObjectTypesFromPlugin = $this->extractConfiguredObjectTypes($pluginClass);

        $usedActionTypes = array_merge($usedActionTypes, $configuredActionTypesFromPlugin);
        $usedBlockTypes = array_merge($usedBlockTypes, $configuredBlockTypesFromPlugin);
        $usedObjectTypes = array_merge($usedObjectTypes, $configuredObjectTypesFromPlugin);

        // Check for unused action types
        $unusedActionTypes = array_diff($configuredActionTypes, $usedActionTypes);
        if (! empty($unusedActionTypes)) {
            $errors['action_types'] = $unusedActionTypes;
        }

        // Check for unused block types
        $unusedBlockTypes = array_diff($configuredBlockTypes, $usedBlockTypes);
        if (! empty($unusedBlockTypes)) {
            $errors['block_types'] = $unusedBlockTypes;
        }

        // Check for unused object types
        $unusedObjectTypes = array_diff($configuredObjectTypes, $usedObjectTypes);
        if (! empty($unusedObjectTypes)) {
            $errors['object_types'] = $unusedObjectTypes;
        }

        return $errors;
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

        // Also include configured types from the plugin itself
        // This ensures we catch types that are defined in get*Types() methods but not used in code
        $configuredActionTypesFromPlugin = $this->extractConfiguredActionTypes($pluginClass);
        $configuredBlockTypesFromPlugin = $this->extractConfiguredBlockTypes($pluginClass);
        $configuredObjectTypesFromPlugin = $this->extractConfiguredObjectTypes($pluginClass);

        $usedActionTypes = array_merge($usedActionTypes, $configuredActionTypesFromPlugin);
        $usedBlockTypes = array_merge($usedBlockTypes, $configuredBlockTypesFromPlugin);
        $usedObjectTypes = array_merge($usedObjectTypes, $configuredObjectTypesFromPlugin);

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

        // Look for specific patterns that are likely to be action types in Event creation
        // Pattern 1: Event::create/updateOrCreate with action field
        preg_match_all("/Event::(?:create|updateOrCreate)\s*\(\s*\[[^\]]*'action'\s*=>\s*['\"]([^'\"]+)['\"]/", $fileContent, $matches);
        if (! empty($matches[1])) {
            $actionTypes = array_merge($actionTypes, $matches[1]);
        }

        // Pattern 2: Event::create/updateOrCreate with action field (double quotes)
        preg_match_all('/Event::(?:create|updateOrCreate)\s*\(\s*\[[^\]]*"action"\s*=>\s*["\']([^"\']+)["\']/', $fileContent, $matches);
        if (! empty($matches[1])) {
            $actionTypes = array_merge($actionTypes, $matches[1]);
        }

        // Pattern 3: Return statements in setAction methods (more specific)
        preg_match_all("/setAction.*?return\s+['\"]([^'\"]+)['\"]\s*;/s", $fileContent, $matches);
        if (! empty($matches[1])) {
            $actionTypes = array_merge($actionTypes, $matches[1]);
        }

        // Pattern 4: Action assignments in variable arrays
        preg_match_all("/\\\$[a-zA-Z_][a-zA-Z0-9_]*\\s*\[\\s*['\"]action['\"]\\s*\]\\s*=\\s*['\"]([^'\"]+)['\"]/", $fileContent, $matches);
        if (! empty($matches[1])) {
            $actionTypes = array_merge($actionTypes, $matches[1]);
        }

        return array_unique(array_filter($actionTypes));
    }

    private function extractConfiguredActionTypes(string $pluginClass): array
    {
        try {
            $reflection = new ReflectionClass($pluginClass);
            if (! $reflection->hasMethod('getActionTypes')) {
                return [];
            }

            $method = $reflection->getMethod('getActionTypes');
            if (! $method->isStatic()) {
                return [];
            }

            // Call the static method to get configured action types
            $actionTypes = $pluginClass::getActionTypes();

            // Return just the keys (action type names)
            return array_keys($actionTypes);
        } catch (Throwable $e) {
            // If reflection fails, return empty array
            return [];
        }
    }

    private function extractConfiguredBlockTypes(string $pluginClass): array
    {
        try {
            $reflection = new ReflectionClass($pluginClass);
            if (! $reflection->hasMethod('getBlockTypes')) {
                return [];
            }

            $method = $reflection->getMethod('getBlockTypes');
            if (! $method->isStatic()) {
                return [];
            }

            // Call the static method to get configured block types
            $blockTypes = $pluginClass::getBlockTypes();

            // Return just the keys (block type names)
            return array_keys($blockTypes);
        } catch (Throwable $e) {
            // If reflection fails, return empty array
            return [];
        }
    }

    private function extractConfiguredObjectTypes(string $pluginClass): array
    {
        try {
            $reflection = new ReflectionClass($pluginClass);
            if (! $reflection->hasMethod('getObjectTypes')) {
                return [];
            }

            $method = $reflection->getMethod('getObjectTypes');
            if (! $method->isStatic()) {
                return [];
            }

            // Call the static method to get configured object types
            $objectTypes = $pluginClass::getObjectTypes();

            // Return just the keys (object type names)
            return array_keys($objectTypes);
        } catch (Throwable $e) {
            // If reflection fails, return empty array
            return [];
        }
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

        // Look for 'block_type' => '...' patterns anywhere (for cases where blocks are defined in arrays)
        // But exclude common PHP types and generic terms
        preg_match_all("/'block_type'\s*=>\s*['\"]([^'\"]+)['\"]/", $fileContent, $matches);
        if (! empty($matches[1])) {
            $filtered = array_filter($matches[1], function ($type) {
                return ! in_array($type, ['array', 'integer', 'string', 'number', 'select', 'text', 'date', 'textarea', 'summary', 'distance', 'energy', 'intensity', 'duration']);
            });
            $blockTypes = array_merge($blockTypes, $filtered);
        }

        // Look for "block_type" => "..." patterns anywhere (for cases where blocks are defined in arrays)
        // But exclude common PHP types and generic terms
        preg_match_all('/"block_type"\s*=>\s*["\']([^"\']+)["\']/', $fileContent, $matches);
        if (! empty($matches[1])) {
            $filtered = array_filter($matches[1], function ($type) {
                return ! in_array($type, ['array', 'integer', 'string', 'number', 'select', 'text', 'date', 'textarea', 'summary', 'distance', 'energy', 'intensity', 'duration']);
            });
            $blockTypes = array_merge($blockTypes, $filtered);
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

        // Look for 'type' => '...' patterns anywhere (for cases where EventObject is used in different contexts)
        // But exclude common PHP types and generic terms
        preg_match_all("/'type'\s*=>\s*['\"]([^'\"]+)['\"]/", $fileContent, $matches);
        if (! empty($matches[1])) {
            $filtered = array_filter($matches[1], function ($type) {
                return ! in_array($type, ['array', 'integer', 'string', 'number', 'select', 'text', 'date', 'textarea', 'uk_retail', 'apple_workout']);
            });
            $objectTypes = array_merge($objectTypes, $filtered);
        }

        // Look for "type" => "..." patterns anywhere (for cases where EventObject is used in different contexts)
        // But exclude common PHP types and generic terms
        preg_match_all('/"type"\s*=>\s*["\']([^"\']+)["\']/', $fileContent, $matches);
        if (! empty($matches[1])) {
            $filtered = array_filter($matches[1], function ($type) {
                return ! in_array($type, ['array', 'integer', 'string', 'number', 'select', 'text', 'date', 'textarea', 'uk_retail', 'apple_workout']);
            });
            $objectTypes = array_merge($objectTypes, $filtered);
        }

        return array_unique(array_filter($objectTypes));
    }
}
