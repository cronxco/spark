<?php

namespace App\Integrations\Contracts;

interface SupportsValueMapping
{
    /**
     * Get value mappings for non-numeric fields
     */
    public static function getValueMappings(): array;

    /**
     * Map a non-numeric value to numeric for storage
     */
    public function mapValueForStorage(string $mappingKey, mixed $value): ?float;
}
