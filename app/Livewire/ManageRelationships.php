<?php

namespace App\Livewire;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Relationship;
use App\Services\RelationshipTypeRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class ManageRelationships extends Component
{
    public string $modelType;
    public string $modelId;

    protected $listeners = [
        'relationship-created' => 'handleRelationshipCreated',
    ];

    public function mount(string $modelType, string $modelId): void
    {
        // Validate model type
        if (! in_array($modelType, [Event::class, EventObject::class, Block::class])) {
            abort(404);
        }

        $this->modelType = $modelType;
        $this->modelId = $modelId;

        // Verify ownership by loading model
        $this->getModel();
    }

    public function getRelationshipsProperty()
    {
        $model = $this->getModel();
        $model->load(['relationshipsFrom.to', 'relationshipsTo.from']);

        return $model->allRelationships()->get();
    }

    public function deleteRelationship(string $relationshipId): void
    {
        $relationship = Relationship::findOrFail($relationshipId);

        // Verify ownership
        if ($relationship->user_id !== Auth::id()) {
            abort(403);
        }

        $relationship->delete();

        $this->dispatch('relationship-deleted');
    }

    public function openAddRelationshipModal(): void
    {
        // Close parent modal and dispatch event to open add relationship modal
        $this->dispatch('close-modal');
        $this->dispatch('open-add-relationship-modal', [
            'modelType' => $this->modelType,
            'modelId' => $this->modelId,
        ]);
    }

    public function handleRelationshipCreated(): void
    {
        // Refresh relationships and reopen this modal
        $this->dispatch('relationship-created');
        $this->dispatch('open-manage-relationships-modal');
    }

    public function getRelationshipIcon(string $type): string
    {
        return RelationshipTypeRegistry::getIcon($type);
    }

    public function getRelationshipDisplayName(string $type): string
    {
        return RelationshipTypeRegistry::getDisplayName($type);
    }

    public function isDirectional(string $type): bool
    {
        return RelationshipTypeRegistry::isDirectional($type);
    }

    public function render()
    {
        return view('livewire.manage-relationships', [
            'relationships' => $this->relationships,
            'model' => $this->getModel(),
        ]);
    }

    protected function getModel(): Model
    {
        $model = $this->modelType::findOrFail($this->modelId);

        // Check ownership based on model type
        if ($model instanceof Event) {
            $integration = $model->integration;
            if (! $integration || $integration->user_id !== Auth::id()) {
                abort(403);
            }
        } elseif ($model instanceof Block) {
            $integration = $model->event?->integration;
            if (! $integration || $integration->user_id !== Auth::id()) {
                abort(403);
            }
        } elseif ($model instanceof EventObject) {
            if ($model->user_id !== Auth::id()) {
                abort(403);
            }
        }

        return $model;
    }
}
