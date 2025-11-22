<?php

namespace App\Livewire\Media;

use Exception;
use Livewire\Component;
use Mary\Traits\Toast;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Show extends Component
{
    use Toast;

    public Media $media;

    public bool $showSidebar = false;

    public bool $showEditModal = false;

    public bool $showDeleteConfirm = false;

    // Sidebar collapse states
    public bool $detailsOpen = true;

    public bool $technicalOpen = false;

    public bool $conversionsOpen = false;

    public bool $activityOpen = true;

    // Edit form fields
    public string $editName = '';

    public string $editFileName = '';

    public array $editCustomProperties = [];

    protected $listeners = [
        'media-updated' => '$refresh',
        'media-deleted' => 'handleMediaDeleted',
    ];

    public function mount(Media $media): void
    {
        $this->media = $media->load(['model']);
        $this->resetEditForm();
    }

    public function resetEditForm(): void
    {
        $this->editName = $this->media->name ?? '';
        $this->editFileName = $this->media->file_name;
        $this->editCustomProperties = $this->media->custom_properties ?? [];
    }

    public function toggleSidebar(): void
    {
        $this->showSidebar = ! $this->showSidebar;
    }

    public function openEditModal(): void
    {
        $this->resetEditForm();
        $this->showEditModal = true;
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
        $this->resetEditForm();
    }

    public function saveEdit(): void
    {
        $this->validate([
            'editName' => 'required|string|max:255',
            'editFileName' => 'required|string|max:255',
        ]);

        try {
            $this->media->update([
                'name' => $this->editName,
                'file_name' => $this->editFileName,
                'custom_properties' => $this->editCustomProperties,
            ]);

            $this->success('Media updated successfully.');
            $this->showEditModal = false;
            $this->dispatch('media-updated');
        } catch (Exception $e) {
            $this->error('Failed to update media: ' . $e->getMessage());
        }
    }

    public function openDeleteConfirm(): void
    {
        $this->showDeleteConfirm = true;
    }

    public function closeDeleteConfirm(): void
    {
        $this->showDeleteConfirm = false;
    }

    public function deleteMedia(): void
    {
        try {
            $mediaId = $this->media->id;
            $this->media->delete();

            $this->success('Media deleted successfully.');
            $this->dispatch('media-deleted', mediaId: $mediaId);
            $this->redirect(route('media.index'), navigate: true);
        } catch (Exception $e) {
            $this->error('Failed to delete media: ' . $e->getMessage());
        }
    }

    public function regenerateConversions(): void
    {
        try {
            // Queue regeneration job
            $this->media->regenerateConversions();

            $this->success('Conversion regeneration queued. This may take a few moments.');
        } catch (Exception $e) {
            $this->error('Failed to regenerate conversions: ' . $e->getMessage());
        }
    }

    public function getConversions()
    {
        $conversions = [];
        $conversionNames = ['thumbnail', 'medium', 'webp'];

        foreach ($conversionNames as $conversion) {
            if ($this->media->hasGeneratedConversion($conversion)) {
                $conversions[] = [
                    'name' => $conversion,
                    'url' => $this->media->getUrl($conversion),
                    'path' => $this->media->getPath($conversion),
                ];
            }
        }

        return collect($conversions);
    }

    public function render()
    {
        return view('livewire.media.show', [
            'conversions' => $this->getConversions(),
        ]);
    }
}
