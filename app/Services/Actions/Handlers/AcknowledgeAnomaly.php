<?php

namespace App\Services\Actions\Handlers;

use App\Models\MetricTrend;
use App\Models\User;
use App\Services\Actions\ActionFailed;
use App\Services\Actions\ActionHandler;
use App\Services\Actions\ActionOutcome;
use App\Services\Actions\Reversible;
use App\Services\Mobile\AnomalyAcknowledgement;

class AcknowledgeAnomaly implements ActionHandler, Reversible
{
    public function __construct(private AnomalyAcknowledgement $acknowledgement) {}

    public function handle(User $user, array $target): ActionOutcome
    {
        $id = $target['anomaly_id'];
        $wasAcknowledged = MetricTrend::query()->whereKey($id)->value('acknowledged_at') !== null;

        if (! $this->acknowledgement->acknowledge($user, $id)) {
            throw ActionFailed::notFound('Anomaly');
        }

        // Undo only what this run changed.
        return ActionOutcome::succeeded('Anomaly dismissed', ['anomaly_id' => $id], $wasAcknowledged ? null : ['anomaly_id' => $id]);
    }

    public function undo(User $user, array $undo): void
    {
        $this->acknowledgement->unacknowledge($user, $undo['anomaly_id']);
    }
}
