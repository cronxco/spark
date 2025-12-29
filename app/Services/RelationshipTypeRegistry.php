<?php

namespace App\Services;

class RelationshipTypeRegistry
{
    /**
     * Get all registered relationship types with their configuration.
     *
     * @return array<string, array{display_name: string, icon: string, is_directional: bool, description: string, supports_value: bool, default_value_unit?: string}>
     */
    public static function getTypes(): array
    {
        return [
            'linked_to' => [
                'display_name' => 'Linked To',
                'icon' => 'fas.link',
                'is_directional' => true,
                'description' => 'Source links to target',
                'supports_value' => false,
            ],
            'related_to' => [
                'display_name' => 'Related To',
                'icon' => 'fas.tag',
                'is_directional' => false,
                'description' => 'General association between entities',
                'supports_value' => false,
            ],
            'caused_by' => [
                'display_name' => 'Caused By',
                'icon' => 'fas.circle-arrow-right',
                'is_directional' => true,
                'description' => 'Effect caused by source',
                'supports_value' => false,
            ],
            'part_of' => [
                'display_name' => 'Part Of',
                'icon' => 'fas.grip',
                'is_directional' => true,
                'description' => 'Entity is part of another entity',
                'supports_value' => false,
            ],
            'similar_to' => [
                'display_name' => 'Similar To',
                'icon' => 'fas.right-left',
                'is_directional' => false,
                'description' => 'Entities are similar (ML-based or manual)',
                'supports_value' => false,
            ],
            'transferred_to' => [
                'display_name' => 'Transferred To',
                'icon' => 'fas.money-bills',
                'is_directional' => true,
                'description' => 'Money or value transferred from source to target',
                'supports_value' => true,
                'default_value_unit' => 'GBP',
            ],
            'triggered_by' => [
                'display_name' => 'Triggered By',
                'icon' => 'o-bolt',
                'is_directional' => true,
                'description' => 'Transaction was automatically triggered by another (e.g., coin jar, rewards)',
                'supports_value' => false,
            ],
            'funded_by' => [
                'display_name' => 'Funded By',
                'icon' => 'o-currency-pound',
                'is_directional' => true,
                'description' => 'Transaction was funded by a pot withdrawal or transfer',
                'supports_value' => true,
                'default_value_unit' => 'GBP',
            ],
            'payment_for' => [
                'display_name' => 'Payment For',
                'icon' => 'o-credit-card',
                'is_directional' => true,
                'description' => 'Cross-provider payment relationship (e.g., direct debit paying off credit card)',
                'supports_value' => true,
                'default_value_unit' => 'GBP',
            ],
            'settles' => [
                'display_name' => 'Settles',
                'icon' => 'o-check-circle',
                'is_directional' => true,
                'description' => 'Transaction settles a pending authorisation',
                'supports_value' => false,
            ],
            'receipt_for' => [
                'display_name' => 'Receipt For',
                'icon' => 'fas.receipt',
                'is_directional' => true,
                'description' => 'Receipt evidence for financial transaction',
                'supports_value' => true,
                'default_value_unit' => 'GBP',
            ],
            'supports' => [
                'display_name' => 'Supports',
                'icon' => 'o-hand-thumb-up',
                'is_directional' => true,
                'description' => 'Evidence or insight that supports another insight (Flint agent)',
                'supports_value' => false,
            ],
            'correlates_with' => [
                'display_name' => 'Correlates With',
                'icon' => 'o-arrows-right-left',
                'is_directional' => false,
                'description' => 'Statistical correlation between insights or patterns (Flint agent)',
                'supports_value' => false,
            ],
            'derived_from' => [
                'display_name' => 'Derived From',
                'icon' => 'o-arrow-up-right',
                'is_directional' => true,
                'description' => 'Insight was derived from source data or another insight (Flint agent)',
                'supports_value' => false,
            ],
            'contradicts' => [
                'display_name' => 'Contradicts',
                'icon' => 'o-x-circle',
                'is_directional' => true,
                'description' => 'Insight contradicts or conflicts with another insight (Flint agent)',
                'supports_value' => false,
            ],
            'occurred_at' => [
                'display_name' => 'Occurred At',
                'icon' => 'o-map-pin',
                'is_directional' => true,
                'description' => 'Event occurred at a specific place',
                'supports_value' => false,
            ],
            'tagged_in' => [
                'display_name' => 'Tagged In',
                'icon' => 'fas.user-tag',
                'is_directional' => true,
                'description' => 'Person appears in photo cluster',
                'supports_value' => false,
            ],
        ];
    }

    /**
     * Get configuration for a specific relationship type.
     *
     * @return array{display_name: string, icon: string, is_directional: bool, description: string, supports_value: bool, default_value_unit?: string}|null
     */
    public static function getType(string $type): ?array
    {
        return self::getTypes()[$type] ?? null;
    }

    /**
     * Check if a relationship type is directional.
     */
    public static function isDirectional(string $type): bool
    {
        $config = self::getType($type);

        return $config ? $config['is_directional'] : true;
    }

    /**
     * Check if a relationship type supports value field.
     */
    public static function supportsValue(string $type): bool
    {
        $config = self::getType($type);

        return $config ? $config['supports_value'] : false;
    }

    /**
     * Get icon for a relationship type.
     */
    public static function getIcon(string $type): ?string
    {
        $config = self::getType($type);

        return $config ? $config['icon'] : null;
    }

    /**
     * Get display name for a relationship type.
     */
    public static function getDisplayName(string $type): ?string
    {
        $config = self::getType($type);

        return $config ? $config['display_name'] : null;
    }

    /**
     * Get description for a relationship type.
     */
    public static function getDescription(string $type): ?string
    {
        $config = self::getType($type);

        return $config ? $config['description'] : null;
    }

    /**
     * Get default value unit for a relationship type.
     */
    public static function getDefaultValueUnit(string $type): ?string
    {
        $config = self::getType($type);

        return $config['default_value_unit'] ?? null;
    }

    /**
     * Get all relationship type keys.
     *
     * @return array<int, string>
     */
    public static function getTypeKeys(): array
    {
        return array_keys(self::getTypes());
    }

    /**
     * Check if a relationship type exists.
     */
    public static function typeExists(string $type): bool
    {
        return array_key_exists($type, self::getTypes());
    }
}
