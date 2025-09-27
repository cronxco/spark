<?php

namespace App\Integrations\Oura;

use InvalidArgumentException;

class BlockTypeRegistry
{
    /**
     * Get valid block types from the OuraPlugin
     */
    public static function getValidBlockTypes(): array
    {
        return array_keys(OuraPlugin::getBlockTypes());
    }

    /**
     * Validate that a block type is registered
     */
    public static function validateBlockType(string $blockType): bool
    {
        return in_array($blockType, self::getValidBlockTypes());
    }

    /**
     * Get block type configuration
     */
    public static function getBlockTypeConfig(string $blockType): array
    {
        $blockTypes = OuraPlugin::getBlockTypes();

        if (! isset($blockTypes[$blockType])) {
            throw new InvalidArgumentException("Unknown block type: {$blockType}");
        }

        return $blockTypes[$blockType];
    }

    /**
     * Get block type display name
     */
    public static function getBlockTypeDisplayName(string $blockType): string
    {
        $config = self::getBlockTypeConfig($blockType);

        return $config['display_name'] ?? ucwords(str_replace('_', ' ', $blockType));
    }

    /**
     * Get block type icon
     */
    public static function getBlockTypeIcon(string $blockType): string
    {
        $config = self::getBlockTypeConfig($blockType);

        return $config['icon'] ?? 'o-square-3-stack-3d';
    }

    /**
     * Get block type value unit
     */
    public static function getBlockTypeValueUnit(string $blockType): ?string
    {
        $config = self::getBlockTypeConfig($blockType);

        return $config['value_unit'] ?? null;
    }

    /**
     * Check if block type is hidden
     */
    public static function isBlockTypeHidden(string $blockType): bool
    {
        $config = self::getBlockTypeConfig($blockType);

        return $config['hidden'] ?? false;
    }

    /**
     * Get all block type configurations
     */
    public static function getAllBlockTypes(): array
    {
        return OuraPlugin::getBlockTypes();
    }

    /**
     * Get block types by category/domain
     */
    public static function getBlockTypesByCategory(string $category): array
    {
        $blockTypes = self::getAllBlockTypes();
        $result = [];

        foreach ($blockTypes as $key => $config) {
            if (($config['category'] ?? null) === $category) {
                $result[$key] = $config;
            }
        }

        return $result;
    }

    /**
     * Create a valid block type if it doesn't exist or validate if it does
     */
    public static function ensureValidBlockType(string $blockType): string
    {
        if (self::validateBlockType($blockType)) {
            return $blockType;
        }

        // Default fallback - could be enhanced to suggest closest match
        return 'activity_metrics'; // Most common default
    }

    /**
     * Get recommended block type for a given field/context
     */
    public static function getRecommendedBlockType(string $field, string $context = 'general'): string
    {
        $fieldMappings = [
            // Sleep-related fields
            'total_sleep_duration' => 'sleep_stages',
            'deep_sleep_duration' => 'sleep_stages',
            'light_sleep_duration' => 'sleep_stages',
            'rem_sleep_duration' => 'sleep_stages',
            'bedtime_start' => 'sleep_stages',
            'bedtime_end' => 'sleep_stages',
            'sleep_efficiency' => 'sleep_stages',

            // Activity-related fields
            'steps' => 'activity_metrics',
            'cal_total' => 'activity_metrics',
            'active_calories' => 'activity_metrics',
            'distance' => 'activity_metrics',
            'met_minutes' => 'activity_metrics',

            // Heart rate fields
            'heart_rate' => 'heart_rate',
            'resting_heart_rate' => 'heart_rate',
            'average_heart_rate' => 'heart_rate',
            'min_heart_rate' => 'heart_rate',
            'max_heart_rate' => 'heart_rate',

            // Biometric fields
            'temperature' => 'biometrics',
            'hrv' => 'biometrics',
            'spo2' => 'biometrics',

            // Contributors/scores
            'contributors' => 'contributors',
            'score' => 'contributors',

            // Workout fields
            'calories' => 'workout_metrics',
            'duration' => 'workout_metrics',
            'intensity' => 'workout_metrics',

            // Tags and annotations
            'tag' => 'tag_info',
            'annotation' => 'tag_info',
            'note' => 'tag_info',
        ];

        // Check exact field match first
        if (isset($fieldMappings[$field])) {
            return $fieldMappings[$field];
        }

        // Check partial matches
        foreach ($fieldMappings as $pattern => $blockType) {
            if (str_contains($field, $pattern)) {
                return $blockType;
            }
        }

        // Context-based defaults
        return match ($context) {
            'sleep' => 'sleep_stages',
            'activity' => 'activity_metrics',
            'workout' => 'workout_metrics',
            'heart_rate', 'heartrate' => 'heart_rate',
            'biometric' => 'biometrics',
            'tag' => 'tag_info',
            default => 'activity_metrics'
        };
    }
}
