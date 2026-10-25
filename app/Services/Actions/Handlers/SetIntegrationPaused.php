<?php

namespace App\Services\Actions\Handlers;

use App\Models\User;
use App\Services\Actions\ActionFailed;
use App\Services\Actions\ActionHandler;
use App\Services\Actions\ActionOutcome;
use App\Services\Actions\Applicable;
use App\Services\Actions\Reversible;
use App\Services\GoCardlessAccounts;

class SetIntegrationPaused implements ActionHandler, Applicable, Reversible
{
    /** Offer Pause on a running integration and Resume on a paused one. */
    public function applies(User $user, array $target): bool
    {
        $integration = $user->integrations()->find($target['integration_id']);

        return $integration !== null && $integration->isPaused() !== (bool) $target['paused'];
    }

    public function handle(User $user, array $target): ActionOutcome
    {
        $integration = $user->integrations()->find($target['integration_id']) ?? throw ActionFailed::notFound('Integration');
        $paused = (bool) $target['paused'];
        $changed = $integration->isPaused() !== $paused;

        if ($changed) {
            $this->set($user, $target['integration_id'], $paused);
        }

        $result = ['integration_id' => $integration->id, 'paused' => $paused];

        // Undo only what this run changed.
        return ActionOutcome::succeeded($paused ? 'Integration paused' : 'Integration resumed', $result, $changed ? [
            'integration_id' => $integration->id,
            'paused' => ! $paused,
        ] : null);
    }

    public function undo(User $user, array $undo): void
    {
        $this->set($user, $undo['integration_id'], (bool) $undo['paused']);
    }

    private function set(User $user, string $integrationId, bool $paused): void
    {
        $integration = $user->integrations()->find($integrationId) ?? throw ActionFailed::notFound('Integration');

        if ($integration->service === 'gocardless') {
            app(GoCardlessAccounts::class)->setPaused($integration, $paused);

            return;
        }

        $configuration = $integration->configuration ?? [];
        $configuration['paused'] = $paused;
        $integration->update(['configuration' => $configuration]);
    }
}
