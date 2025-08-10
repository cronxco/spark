<?php

namespace App\Integrations\Contracts;

use App\Models\IntegrationGroup;

interface OAuthIntegrationPlugin extends IntegrationPlugin
{
    /**
     * Service type discriminator for registry filters
     */
    public static function getServiceType(): string;

    /**
     * Return the OAuth authorization URL for the given group
     */
    public function getOAuthUrl(IntegrationGroup $group): string;
}


