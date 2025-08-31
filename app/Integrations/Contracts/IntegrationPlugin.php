<?php

namespace App\Integrations\Contracts;

use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Http\Request;

interface IntegrationPlugin
{
    /**
     * Get the unique identifier for this integration
     */
    public static function getIdentifier(): string;

    /**
     * Get the display name for this integration
     */
    public static function getDisplayName(): string;

    /**
     * Get the description for this integration
     */
    public static function getDescription(): string;

    /**
     * Get the service type (oauth, webhook, etc.)
     */
    public static function getServiceType(): string;

    /**
     * Get the configuration schema for this integration
     */
    public static function getConfigurationSchema(): array;

    /**
     * Return the available instance types and their initial config schemas
     * e.g. ['sleep' => ['label' => 'Sleep', 'schema' => [...]], ...]
     */
    public static function getInstanceTypes(): array;

    /**
     * Get the integration icon (Heroicon name or custom icon identifier)
     */
    public static function getIcon(): string;

    /**
     * Get the integration accent color (daisyUI color name)
     */
    public static function getAccentColor(): string;

    /**
     * Get the integration domain (e.g., 'media', 'health', 'money', 'online')
     */
    public static function getDomain(): string;

    /**
     * Get the integration action types configuration
     *
     * @return array<string, array{
     *   icon: string,
     *   display_name: string,
     *   description: string,
     *   display_with_object: bool,
     *   value_unit: string|null,
     *   hidden: bool
     * }>
     */
    public static function getActionTypes(): array;

    /**
     * Get the integration block types configuration
     *
     * @return array<string, array{
     *   icon: string,
     *   display_name: string,
     *   description: string,
     *   display_with_object: bool,
     *   value_unit: string|null,
     *   hidden: bool
     * }>
     */
    public static function getBlockTypes(): array;

    /**
     * Get the integration object types configuration
     *
     * @return array<string, array{
     *   icon: string,
     *   display_name: string,
     *   description: string,
     *   hidden: bool
     * }>
     */
    public static function getObjectTypes(): array;

    /**
     * Initialize an auth group (for OAuth/webhook setup)
     */
    public function initializeGroup(User $user): IntegrationGroup;

    /**
     * Create an instance attached to a group
     */
    public function createInstance(IntegrationGroup $group, string $instanceType, array $initialConfig = []): Integration;

    /**
     * Handle OAuth callback
     */
    public function handleOAuthCallback(Request $request, IntegrationGroup $group): void;

    /**
     * Handle webhook payload
     */
    public function handleWebhook(Request $request, Integration $integration): void;

    /**
     * Fetch data from external API
     */
    public function fetchData(Integration $integration): void;

    /**
     * Convert external data to our format
     */
    public function convertData(array $externalData, Integration $integration): array;
}
