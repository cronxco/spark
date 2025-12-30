<?php

namespace App\Integrations\Contracts;

interface SupportsEffects
{
    /**
     * Return effect definitions that users can trigger.
     *
     * Effects are user-initiated actions that plugins can perform on external services,
     * such as updating data, creating resources, or triggering workflows.
     *
     * @return array<string, array{
     *     title: string,
     *     description: string,
     *     icon: string,
     *     jobClass: string,
     *     queue?: string,
     *     requiresConfirmation?: bool,
     *     confirmationMessage?: string,
     *     successMessage?: string,
     *     availableIn?: array<string>
     * }>
     */
    public static function getEffects(): array;
}
