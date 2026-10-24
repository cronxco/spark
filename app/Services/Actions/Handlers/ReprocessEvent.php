<?php

namespace App\Services\Actions\Handlers;

use App\Models\User;
use App\Services\Actions\ActionFailed;
use App\Services\Actions\ActionHandler;
use App\Services\Actions\ActionOutcome;
use App\Services\Knowledge\KnowledgeReprocessingService;
use InvalidArgumentException;
use RuntimeException;

class ReprocessEvent implements ActionHandler
{
    public function __construct(private KnowledgeReprocessingService $reprocessing) {}

    public function handle(User $user, array $target): ActionOutcome
    {
        try {
            $payload = $this->reprocessing->reprocessForUser($user, $target['event_id']);
        } catch (RuntimeException $e) {
            throw $e->getCode() === 404 ? ActionFailed::notFound('Event') : $e;
        } catch (InvalidArgumentException $e) {
            throw new ActionFailed($e->getMessage(), 'invalid');
        }

        return ActionOutcome::accepted('Reprocessing queued', $payload);
    }
}
