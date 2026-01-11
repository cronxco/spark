<?php

namespace App\Exceptions;

use Exception;

class GoCardlessEuaExpiredException extends Exception
{
    public function __construct(
        protected string $groupId,
        protected array $errorResponse
    ) {
        $summary = $errorResponse['summary'] ?? 'EUA has expired';
        $detail = $errorResponse['detail'] ?? '';

        parent::__construct("GoCardless EUA expired: {$summary}. {$detail}");
    }

    public function getGroupId(): string
    {
        return $this->groupId;
    }

    public function getEuaId(): ?string
    {
        // Extract EUA ID from error response detail
        // Example: "EUA 7df396d0-844e-41cd-bc32-e62b7f65b154 has expired"
        $detail = $this->errorResponse['detail'] ?? '';
        if (preg_match('/EUA ([a-f0-9-]+)/i', $detail, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public function getErrorResponse(): array
    {
        return $this->errorResponse;
    }
}
