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
     * Initialize the integration for a user
     */
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