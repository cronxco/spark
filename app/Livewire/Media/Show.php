<?php

namespace App\Livewire\Media;

use App\Models\Block;
use App\Models\EventObject;
use App\Traits\AuthorizesOwnership;
use Exception;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Mary\Traits\Toast;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Show extends Component
{
    use AuthorizesOwnership, Toast;

    public Media $media;

    public bool $showSidebar = false;

    public bool $showEditModal = false;

    public bool $showDeleteConfirm = false;

    public bool $showInstancesModal = false;

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
        $this->authorizeMedia();
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
        $this->authorizeMedia();

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
        $this->authorizeMedia();

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

    public function openInstancesModal(): void
    {
        $this->showInstancesModal = true;
    }

    public function closeInstancesModal(): void
    {
        $this->showInstancesModal = false;
    }

    /**
     * Get all instances of this media (same MD5 hash)
     */
    public function getAllInstances()
    {
        $md5Hash = $this->media->getCustomProperty('md5_hash');

        if (! $md5Hash) {
            return collect();
        }

        return Media::where('custom_properties->md5_hash', $md5Hash)
            ->with(['model'])
            ->orderBy('created_at', 'desc')
            ->get()
            // Don't reveal other users' copies of a deduplicated file.
            ->filter(fn (Media $instance) => $this->ownerIdFor($instance) === Auth::id())
            ->values();
    }

    /**
     * Get count of all instances
     */
    public function getInstancesCount(): int
    {
        return $this->getAllInstances()->count();
    }

    /**
     * Check if we're using S3 storage
     */
    public function isS3(): bool
    {
        return config('media-library.disk_name') === 's3';
    }

    /**
     * Get URL for media (signed for S3, direct for local)
     */
    public function getMediaUrl(?string $conversion = null): string
    {
        if ($this->isS3()) {
            $expiry = now()->addMinutes(60);

            return $conversion
                ? $this->media->getTemporaryUrl($expiry, $conversion)
                : $this->media->getTemporaryUrl($expiry);
        }

        return $conversion
            ? $this->media->getUrl($conversion)
            : $this->media->getUrl();
    }

    /**
     * Get conversions with appropriate URLs
     */
    public function getConversions()
    {
        $conversions = [];
        $conversionNames = ['thumbnail', 'medium', 'webp'];

        foreach ($conversionNames as $conversion) {
            if ($this->media->hasGeneratedConversion($conversion)) {
                $conversions[] = [
                    'name' => $conversion,
                    'url' => $this->getMediaUrl($conversion),
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
            'mediaUrl' => $this->getMediaUrl(),
            'isS3' => $this->isS3(),
            'allInstances' => $this->getAllInstances(),
            'instancesCount' => $this->getInstancesCount(),
        ]);
    }

    /**
     * Resolve the owning user id of a media item via its parent model, then
     * abort 403 unless it belongs to the authenticated user. Media hangs off
     * EventObject (user_id) or Block (through its event's integration); any
     * other parent type is treated as unowned and denied.
     */
    private function authorizeMedia(): void
    {
        $this->authorizeOwner($this->ownerIdFor($this->media));
    }

    /**
     * Resolve the owning user id of an arbitrary media item via its parent model.
     */
    private function ownerIdFor(Media $media): int|string|null
    {
        $model = $media->model;

        return match (true) {
            $model instanceof EventObject => $model->user_id,
            $model instanceof Block => $model->event?->integration?->user_id,
            default => null,
        };
    }
}
