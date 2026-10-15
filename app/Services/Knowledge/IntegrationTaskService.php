<?php

namespace App\Services\Knowledge;

use App\Models\Integration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IntegrationTaskService
{
    public function __construct(private TaskService $tasks) {}

    public function action(Integration $integration, bool $reauth = true): array
    {
        $url = route('integrations.details', $integration->id);
        if ($reauth && $integration->integration_group_id) {
            $url = route('integrations.reconnect', ['service' => $integration->service, 'group' => $integration->integration_group_id]);
        }

        return ['kind' => $reauth ? 'integration_reauth' : 'integration_details', 'integration_id' => (string) $integration->id,
            'url' => $url, 'deep_link' => ($reauth ? 'integration-reauth:' : 'integration:') . $integration->id];
    }

    public function report(User $user, Integration $integration, bool $reauth, bool $consent = false): void
    {
        if (! KnowledgeFeature::enabled($user, 'incident_tasks') || ! KnowledgeFeature::enabled($user, 'writes')) {
            return;
        }
        abort_unless((string) $integration->user_id === (string) $user->id, 403);
        DB::transaction(function () use ($user, $integration, $reauth, $consent) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            Integration::whereKey($integration->id)->lockForUpdate()->firstOrFail();
            $key = $integration->id . ':' . ($consent ? 'consent' : ($reauth ? 'reauth' : 'failure'));
            $block = $this->tasks->query($user)->where('metadata->task->incident->key', $key)->where('metadata->task->status', 'open')->first();
            if (! $block) {
                $p = $this->tasks->create($user, ['title' => ($reauth ? 'Reconnect ' : 'Review ') . ($integration->name ?: ucfirst($integration->service)),
                    'client_mutation_id' => (string) Str::uuid(), 'availability_on' => now($user->getTimezone())->toDateString(), 'priority' => 'important']);
                $block = $this->tasks->find($user, $p['id']);
            }
            $task = $this->tasks->state($block);
            $before = $task;
            $task['origin'] = 'integration';
            $task['incident'] = ['key' => $key, 'integration_id' => (string) $integration->id, 'last_occurred_at' => now()->toIso8601String(),
                'kind' => $consent ? 'consent' : ($reauth ? 'reauth' : 'failure'),
                'consent_reference' => data_get($integration->group?->auth_metadata, 'gocardless_completed_reference'),
                'occurrences' => ($task['incident']['occurrences'] ?? 0) + 1];
            $task['action'] = $this->action($integration, $reauth);
            $this->tasks->save($block, $task, $block->title);
            // A repeat of the same incident only bumps its occurrence count and
            // last_occurred_at. History records the first report and any report
            // that changes what the incident is, so a failure retried every few
            // minutes doesn't add a timeline event each time.
            if ($this->changesIncident($before, $task)) {
                $this->tasks->record($block, $user, 'incident_reported', $before, $task);
            }
        });
    }

    private function changesIncident(array $before, array $after): bool
    {
        $identity = fn (array $task) => [
            data_get($task, 'origin'),
            data_get($task, 'incident.key'),
            data_get($task, 'incident.kind'),
            data_get($task, 'incident.consent_reference'),
            data_get($task, 'action.kind'),
            data_get($task, 'action.url'),
        ];

        return $identity($before) !== $identity($after);
    }

    public function reconcile(Integration $integration): void
    {
        $user = $integration->user;
        if (! $user) {
            return;
        }
        DB::transaction(function () use ($user, $integration) {
            foreach ($this->tasks->query($user)->where('metadata->task->incident->integration_id', (string) $integration->id)->where('metadata->task->status', 'open')->lockForUpdate()->get() as $block) {
                $task = $this->tasks->state($block);
                if (! $integration->trashed() && data_get($task, 'incident.kind') === 'consent'
                    && data_get($integration->group?->auth_metadata, 'gocardless_completed_reference') === data_get($task, 'incident.consent_reference')) {
                    continue;
                }
                if (! $integration->trashed() && (! $integration->last_successful_update_at || $integration->last_successful_update_at->lte($task['incident']['last_occurred_at']))) {
                    continue;
                }
                $before = $task;
                $task['status'] = $integration->trashed() ? 'cancelled' : 'completed';
                $task['completed_at'] = now()->toIso8601String();
                $this->tasks->save($block, $task, $block->title);
                $this->tasks->record($block, $user, 'incident_resolved', $before, $task);
            }
        });
    }
}
