<?php

namespace App\Livewire;

use App\Integrations\PluginRegistry;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use Livewire\Component;

class IntegrationDetails extends Component
{
    public Integration $integration;
    public bool $showSidebar = false;
    public bool $logsOpen = false;
    public bool $configOpen = true;

    public function mount(Integration $integration)
    {
        if ((string) $integration->user_id !== (string) auth()->id()) {
            abort(403);
        }

        $this->integration = $integration->load('group');
    }

    public function toggleSidebar(): void
    {
        $this->showSidebar = ! $this->showSidebar;
    }

    public function triggerIntegrationUpdate(): void
    {
        // Trigger an immediate update for this integration
        $this->integration->trigger();

        $this->dispatch('integration-update-triggered');
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

    public function openConfigureModal(): void
    {
        // This would open a configuration modal - for now just redirect to settings
        $this->redirect(route('integrations.details', $this->integration->id) . '#configuration');
    }

    public function getPluginClass()
    {
        return PluginRegistry::getPlugin($this->integration->service);
    }

    public function getActionTypes()
    {
        $pluginClass = $this->getPluginClass();
        if (! $pluginClass) {
            return [];
        }

        return collect($pluginClass::getActionTypes())->map(function ($action, $key) {
            return [
                'key' => $key,
                'action' => $action,
                'count' => Event::where('integration_id', $this->integration->id)
                    ->where('action', $key)
                    ->count(),
                'recent' => Event::where('integration_id', $this->integration->id)
                    ->where('action', $key)
                    ->with('target')
                    ->orderBy('created_at', 'desc')
                    ->limit(5)
                    ->get(),
                'newest' => Event::where('integration_id', $this->integration->id)
                    ->where('action', $key)
                    ->orderBy('created_at', 'desc')
                    ->first(),
            ];
        });
    }

    public function getObjectTypes()
    {
        $pluginClass = $this->getPluginClass();
        if (! $pluginClass) {
            return [];
        }

        return collect($pluginClass::getObjectTypes())->map(function ($object, $key) {
            return [
                'key' => $key,
                'object' => $object,
                'count' => EventObject::where('type', $key)
                    ->where(function ($query) {
                        $query->whereHas('actorEvents', function ($q) {
                            $q->where('integration_id', $this->integration->id);
                        })->orWhereHas('targetEvents', function ($q) {
                            $q->where('integration_id', $this->integration->id);
                        });
                    })
                    ->count(),
                'recent' => EventObject::where('type', $key)
                    ->where(function ($query) {
                        $query->whereHas('actorEvents', function ($q) {
                            $q->where('integration_id', $this->integration->id);
                        })->orWhereHas('targetEvents', function ($q) {
                            $q->where('integration_id', $this->integration->id);
                        });
                    })
                    ->with(['actorEvents', 'targetEvents'])
                    ->orderBy('created_at', 'desc')
                    ->limit(5)
                    ->get(),
                'newest' => EventObject::where('type', $key)
                    ->where(function ($query) {
                        $query->whereHas('actorEvents', function ($q) {
                            $q->where('integration_id', $this->integration->id);
                        })->orWhereHas('targetEvents', function ($q) {
                            $q->where('integration_id', $this->integration->id);
                        });
                    })
                    ->orderBy('created_at', 'desc')
                    ->first(),
            ];
        });
    }

    public function getBlockTypes()
    {
        $pluginClass = $this->getPluginClass();
        if (! $pluginClass) {
            return [];
        }

        return collect($pluginClass::getBlockTypes())->map(function ($block, $key) {
            return [
                'key' => $key,
                'block' => $block,
                'count' => Block::where('block_type', $key)
                    ->whereHas('event', function ($query) {
                        $query->where('integration_id', $this->integration->id);
                    })
                    ->count(),
                'recent' => Block::where('block_type', $key)
                    ->whereHas('event', function ($query) {
                        $query->where('integration_id', $this->integration->id);
                    })
                    ->with('event')
                    ->orderBy('created_at', 'desc')
                    ->limit(5)
                    ->get(),
                'newest' => Block::where('block_type', $key)
                    ->whereHas('event', function ($query) {
                        $query->where('integration_id', $this->integration->id);
                    })
                    ->orderBy('created_at', 'desc')
                    ->first(),
            ];
        });
    }

    public function render()
    {
        return view('livewire.integration-details')
            ->layout('components.layouts.app', ['title' => $this->integration->name . ' Details']);
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
