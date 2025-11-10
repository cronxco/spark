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

class AddRelationship extends Component
{
    public string $fromType;
    public string $fromId;

    public string $relationshipType = 'related_to';
    public string $toType = '';
    public string $searchQuery = '';
    public ?string $selectedToId = null;

    public ?float $value = null;
    public ?float $valueMultiplier = null;
    public ?string $valueUnit = null;
    public ?string $metadata = null;

    public bool $showAdvanced = false;

    public function mount(string $fromType, string $fromId): void
    {
        // Validate from model type
        if (! in_array($fromType, [Event::class, EventObject::class, Block::class])) {
            abort(404);
        }

        $this->fromType = $fromType;
        $this->fromId = $fromId;

        // Verify ownership by loading model
        $this->getFromModel();
    }

    public function getRelationshipTypesProperty(): array
    {
        return RelationshipTypeRegistry::getTypes();
    }

    public function getSearchResultsProperty()
    {
        if (blank($this->toType) || blank($this->searchQuery)) {
            return collect();
        }

        $query = trim($this->searchQuery);

        // Get user ID from from model
        $fromModel = $this->getFromModel();
        $userId = $fromModel instanceof EventObject
            ? $fromModel->user_id
            : $fromModel->integration->user_id;

        // Search based on selected type
        switch ($this->toType) {
            case Event::class:
                return Event::query()
                    ->whereHas('integration', fn ($q) => $q->where('user_id', $userId))
                    ->where('action', 'like', "%{$query}%")
                    ->orderBy('time', 'desc')
                    ->limit(20)
                    ->get();

            case EventObject::class:
                return EventObject::query()
                    ->where('user_id', $userId)
                    ->where(function ($q) use ($query) {
                        $q->where('title', 'like', "%{$query}%")
                            ->orWhere('concept', 'like', "%{$query}%")
                            ->orWhere('type', 'like', "%{$query}%");
                    })
                    ->orderBy('time', 'desc')
                    ->limit(20)
                    ->get();

            case Block::class:
                return Block::query()
                    ->whereHas('integration', fn ($q) => $q->where('user_id', $userId))
                    ->where('type', 'like', "%{$query}%")
                    ->orderBy('time', 'desc')
                    ->limit(20)
                    ->get();

            default:
                return collect();
        }
    }

    public function selectTarget(string $id): void
    {
        $this->selectedToId = $id;
        $this->searchQuery = ''; // Clear search after selection
    }

    public function clearSelection(): void
    {
        $this->selectedToId = null;
    }

    public function getSelectedTargetProperty(): ?Model
    {
        if (blank($this->selectedToId) || blank($this->toType)) {
            return null;
        }

        return $this->toType::find($this->selectedToId);
    }

    public function save(): void
    {
        $this->validate([
            'relationshipType' => 'required|string',
            'toType' => 'required|string',
            'selectedToId' => 'required|string',
            'value' => 'nullable|numeric',
            'valueMultiplier' => 'nullable|numeric',
            'valueUnit' => 'nullable|string|max:50',
            'metadata' => 'nullable|json',
        ]);

        // Verify selected target exists and user has access
        $toModel = $this->toType::findOrFail($this->selectedToId);

        // Get user ID from from model
        $fromModel = $this->getFromModel();
        $userId = $fromModel instanceof EventObject
            ? $fromModel->user_id
            : $fromModel->integration->user_id;

        // Verify ownership of target
        if ($toModel instanceof Event || $toModel instanceof Block) {
            if ($toModel->integration->user_id !== $userId) {
                abort(403);
            }
        } elseif ($toModel instanceof EventObject) {
            if ($toModel->user_id !== $userId) {
                abort(403);
            }
        }

        // Parse metadata if provided
        $metadataArray = null;
        if (filled($this->metadata)) {
            $metadataArray = json_decode($this->metadata, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->addError('metadata', 'Invalid JSON format');

                return;
            }
        }

        // Create relationship
        Relationship::createRelationship([
            'user_id' => $userId,
            'from_type' => $this->fromType,
            'from_id' => $this->fromId,
            'to_type' => $this->toType,
            'to_id' => $this->selectedToId,
            'type' => $this->relationshipType,
            'value' => $this->value,
            'value_multiplier' => $this->valueMultiplier,
            'value_unit' => $this->valueUnit,
            'metadata' => $metadataArray,
        ]);

        $this->dispatch('relationship-created');
        $this->dispatch('close-modal');
    }

    public function supportsValue(): bool
    {
        return RelationshipTypeRegistry::supportsValue($this->relationshipType);
    }

    public function render()
    {
        return view('livewire.add-relationship', [
            'relationshipTypes' => $this->relationshipTypes,
            'searchResults' => $this->searchResults,
            'selectedTarget' => $this->selectedTarget,
        ]);
    }

    protected function getFromModel(): Model
    {
        $model = $this->fromType::findOrFail($this->fromId);

        // Check ownership based on model type
        if ($model instanceof Event || $model instanceof Block) {
            $integration = $model->integration;
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
