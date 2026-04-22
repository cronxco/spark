<?php

namespace App\Livewire;

use App\Actions\DispatchIntegrationFetchJobs;
use App\Integrations\PluginRegistry;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use Livewire\Component;
use Mary\Traits\Toast;

class IntegrationDetails extends Component
{
    use Toast;

    public Integration $integration;

    public bool $showSidebar = false;

    public bool $configOpen = true;

    // Progressive loading state flags
    public bool $actionTypesLoaded = false;

    public bool $objectTypesLoaded = false;

    public bool $blockTypesLoaded = false;

    public bool $tasksLoaded = false;

    // Collapse states
    public bool $tasksOpen = false;

    // Cached data for loaded types
    public array $cachedActionTypes = [];

    public array $cachedObjectTypes = [];

    public array $cachedBlockTypes = [];

    public function mount(Integration $integration)
    {
        if ((string) $integration->user_id !== (string) auth()->id()) {
            abort(403);
        }

        $this->integration = $integration->load('group');
    }

    /**
     * Load action types with their statistics (expensive)
     */
    public function loadActionTypes(): void
    {
        if ($this->actionTypesLoaded) {
            return;
        }

        $this->cachedActionTypes = $this->fetchActionTypes();
        $this->actionTypesLoaded = true;
    }

    /**
     * Load object types with their statistics (expensive)
     */
    public function loadObjectTypes(): void
    {
        if ($this->objectTypesLoaded) {
            return;
        }

        $this->cachedObjectTypes = $this->fetchObjectTypes();
        $this->objectTypesLoaded = true;
    }

    /**
     * Load block types with their statistics (expensive)
     */
    public function loadBlockTypes(): void
    {
        if ($this->blockTypesLoaded) {
            return;
        }

        $this->cachedBlockTypes = $this->fetchBlockTypes();
        $this->blockTypesLoaded = true;
    }

    /**
     * Load task execution information
     */
    public function loadTasks(): void
    {
        if ($this->tasksLoaded) {
            return;
        }

        // Calculate smart default for collapse state
        $this->tasksOpen = $this->shouldExpandTasksSection();
        $this->tasksLoaded = true;
    }

    public function toggleSidebar(): void
    {
        $this->showSidebar = ! $this->showSidebar;
    }

    public function triggerIntegrationUpdate(): void
    {
        if ($this->integration->isPaused()) {
            $this->error('Integration is paused.');

            return;
        }

        (new DispatchIntegrationFetchJobs)->dispatch($this->integration);

        $this->success('Update triggered successfully!');
    }

    public function toggleIntegrationPause(): void
    {
        // Toggle the paused state
        $config = $this->integration->configuration ?? [];
        $config['paused'] = ! ($config['paused'] ?? false);
        $this->integration->configuration = $config;
        $this->integration->save();

        $this->dispatch('integration-pause-toggled');
    }

    public function reconnectIntegration(): void
    {
        $group = $this->integration->group;
        if (! $group) {
            $this->error('No integration group found.');

            return;
        }

        $this->redirect(route('integrations.reconnect', [
            'service' => $this->integration->service,
            'group' => $group->id,
        ]));
    }

    public function openConfigureModal(): void
    {
        // This would open a configuration modal - for now just redirect to settings
        $this->redirect(route('integrations.details', $this->integration->id) . '#configuration');
    }

    public function getCompleteIntegrationData(): array
    {
        return [
            'integration' => $this->integration->toArray(),
            'group' => $this->integration->group?->toArray(),
            'configuration' => $this->integration->configuration ?? [],
            'events_count' => Event::where('integration_id', $this->integration->id)->count(),
            'recent_events' => Event::where('integration_id', $this->integration->id)
                ->with(['actor', 'target', 'tags'])
                ->orderBy('time', 'desc')
                ->limit(10)
                ->get()
                ->toArray(),
        ];
    }

    public function exportAsJson(): void
    {
        $data = $this->getCompleteIntegrationData();
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->js('
            const blob = new Blob([' . json_encode($json) . "], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'integration-{$this->integration->id}-" . now()->format('Y-m-d-His') . ".json';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);

            const toast = document.createElement('div');
            toast.className = 'toast toast-top toast-center z-50';
            toast.innerHTML = `
                <div class='alert alert-success shadow-lg'>
                    <svg xmlns='http://www.w3.org/2000/svg' class='stroke-current shrink-0 h-5 w-5' fill='none' viewBox='0 0 24 24'>
                        <path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z' />
                    </svg>
                    <span>Integration exported!</span>
                </div>
            `;
            document.body.appendChild(toast);
            setTimeout(() => {
                toast.classList.add('opacity-0');
                setTimeout(() => toast.remove(), 300);
            }, 2000);
        ");
    }

    public function getPluginClass()
    {
        return PluginRegistry::getPlugin($this->integration->service);
    }

    public function getActionTypes()
    {
        if (! $this->actionTypesLoaded) {
            return collect();
        }

        return collect($this->cachedActionTypes);
    }

    public function getObjectTypes()
    {
        if (! $this->objectTypesLoaded) {
            return collect();
        }

        return collect($this->cachedObjectTypes);
    }

    public function getBlockTypes()
    {
        if (! $this->blockTypesLoaded) {
            return collect();
        }

        return collect($this->cachedBlockTypes);
    }

    public function render()
    {
        return view('livewire.integration-details', [
            'pluginClass' => $this->getPluginClass(),
        ])->layout('components.layouts.app', ['title' => $this->integration->name . ' Details']);
    }

    /**
     * Determine if tasks section should be expanded by default
     */
    protected function shouldExpandTasksSection(): bool
    {
        // Expand if there are failed or pending tasks
        // For Integration, task_executions are stored in configuration column
        $configuration = $this->integration->configuration ?? [];
        $executions = $configuration['task_executions'] ?? [];

        foreach ($executions as $execution) {
            $status = $execution['last_attempt']['status'] ?? null;
            if (in_array($status, ['failed', 'pending'])) {
                return true;
            }
        }

        return false;
    }

    protected function fetchActionTypes(): array
    {
        $pluginClass = $this->getPluginClass();
        if (! $pluginClass) {
            return [];
        }

        $actionTypes = $pluginClass::getActionTypes();
        $actionKeys = array_keys($actionTypes);

        // Get all counts in one query using groupBy
        $actionCounts = Event::where('integration_id', $this->integration->id)
            ->selectRaw('action, count(*) as count')
            ->whereIn('action', $actionKeys)
            ->groupBy('action')
            ->pluck('count', 'action');

        // Get all recent events in one query, then group by action
        $allRecentEvents = Event::where('integration_id', $this->integration->id)
            ->whereIn('action', $actionKeys)
            ->with('target')
            ->orderBy('created_at', 'desc')
            ->get();

        $recentByAction = $allRecentEvents->groupBy('action')
            ->map(fn ($group) => $group->take(5));

        // Get newest events (top 1 per action) from the recent events
        $newestByAction = $allRecentEvents->groupBy('action')
            ->map(fn ($group) => $group->first());

        return collect($actionTypes)->map(function ($action, $key) use ($actionCounts, $recentByAction, $newestByAction) {
            return [
                'key' => $key,
                'action' => $action,
                'count' => $actionCounts[$key] ?? 0,
                'recent' => $recentByAction[$key] ?? collect(),
                'newest' => $newestByAction[$key] ?? null,
            ];
        })->toArray();
    }

    protected function fetchObjectTypes(): array
    {
        $pluginClass = $this->getPluginClass();
        if (! $pluginClass) {
            return [];
        }

        $objectTypes = $pluginClass::getObjectTypes();
        $objectTypeKeys = array_keys($objectTypes);

        // Get all object IDs associated with this integration's events
        $objectIds = Event::where('integration_id', $this->integration->id)
            ->select('actor_id', 'target_id')
            ->get()
            ->flatMap(fn ($event) => [$event->actor_id, $event->target_id])
            ->filter()
            ->unique()
            ->values();

        // If no objects found, return early
        if ($objectIds->isEmpty()) {
            return collect($objectTypes)->map(function ($object, $key) {
                return [
                    'key' => $key,
                    'object' => $object,
                    'count' => 0,
                    'recent' => collect(),
                    'newest' => null,
                ];
            })->toArray();
        }

        // Get counts grouped by type
        $objectCounts = EventObject::whereIn('id', $objectIds)
            ->whereIn('type', $objectTypeKeys)
            ->selectRaw('type, count(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type');

        // Get all objects of these types, ordered by created_at
        $allObjects = EventObject::whereIn('id', $objectIds)
            ->whereIn('type', $objectTypeKeys)
            ->orderBy('created_at', 'desc')
            ->get();

        $recentByType = $allObjects->groupBy('type')
            ->map(fn ($group) => $group->take(5));

        $newestByType = $allObjects->groupBy('type')
            ->map(fn ($group) => $group->first());

        return collect($objectTypes)->map(function ($object, $key) use ($objectCounts, $recentByType, $newestByType) {
            return [
                'key' => $key,
                'object' => $object,
                'count' => $objectCounts[$key] ?? 0,
                'recent' => $recentByType[$key] ?? collect(),
                'newest' => $newestByType[$key] ?? null,
            ];
        })->toArray();
    }

    protected function fetchBlockTypes(): array
    {
        $pluginClass = $this->getPluginClass();
        if (! $pluginClass) {
            return [];
        }

        $blockTypes = $pluginClass::getBlockTypes();
        $blockTypeKeys = array_keys($blockTypes);

        // Get all event IDs for this integration
        $eventIds = Event::where('integration_id', $this->integration->id)
            ->pluck('id');

        // If no events found, return early
        if ($eventIds->isEmpty()) {
            return collect($blockTypes)->map(function ($block, $key) {
                return [
                    'key' => $key,
                    'block' => $block,
                    'count' => 0,
                    'recent' => collect(),
                    'newest' => null,
                ];
            })->toArray();
        }

        // Get counts grouped by block_type
        $blockCounts = Block::whereIn('event_id', $eventIds)
            ->whereIn('block_type', $blockTypeKeys)
            ->selectRaw('block_type, count(*) as count')
            ->groupBy('block_type')
            ->pluck('count', 'block_type');

        // Get all blocks of these types, ordered by created_at
        $allBlocks = Block::whereIn('event_id', $eventIds)
            ->whereIn('block_type', $blockTypeKeys)
            ->with('event')
            ->orderBy('created_at', 'desc')
            ->get();

        $recentByType = $allBlocks->groupBy('block_type')
            ->map(fn ($group) => $group->take(5));

        $newestByType = $allBlocks->groupBy('block_type')
            ->map(fn ($group) => $group->first());

        return collect($blockTypes)->map(function ($block, $key) use ($blockCounts, $recentByType, $newestByType) {
            return [
                'key' => $key,
                'block' => $block,
                'count' => $blockCounts[$key] ?? 0,
                'recent' => $recentByType[$key] ?? collect(),
                'newest' => $newestByType[$key] ?? null,
            ];
        })->toArray();
    }

    protected function getListeners(): array
    {
        return [
            // Spotlight command events
            'trigger-integration-update' => 'triggerIntegrationUpdate',
            'toggle-integration-pause' => 'toggleIntegrationPause',
            'open-configure-modal' => 'openConfigureModal',
        ];
    }
}
