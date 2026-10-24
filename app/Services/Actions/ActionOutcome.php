<?php

namespace App\Services\Actions;

final readonly class ActionOutcome
{
    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>|null  $undo
     */
    private function __construct(
        public ActionStatus $status,
        public string $message,
        public array $result = [],
        public ?array $undo = null,
    ) {}

    /**
     * The work was done inside the request.
     *
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>|null  $undo
     */
    public static function succeeded(string $message, array $result = [], ?array $undo = null): self
    {
        return new self(ActionStatus::Succeeded, $message, $result, $undo);
    }

    /**
     * The work was queued. The receipt says so, rather than claiming success
     * for something that has not run yet.
     *
     * @param  array<string, mixed>  $result
     */
    public static function accepted(string $message, array $result = []): self
    {
        return new self(ActionStatus::Accepted, $message, $result);
    }
}
