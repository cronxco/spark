<?php

namespace App\Livewire\Media;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Index extends Component
{
    use Toast, WithPagination;

    public string $search = '';

    public array $modelFilter = [];

    public array $collectionFilter = [];

    public array $mimeFilter = [];

    public array $selectedItems = [];

    public int $perPage = 24;

    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

    protected $queryString = [
        'search' => ['except' => ''],
        'modelFilter' => ['except' => []],
        'collectionFilter' => ['except' => []],
        'mimeFilter' => ['except' => []],
        'sortBy' => ['except' => ['column' => 'created_at', 'direction' => 'desc']],
        'perPage' => ['except' => 24],
        'page' => ['except' => 1],
    ];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedModelFilter(): void
    {
        $this->resetPage();
    }

    public function updatedCollectionFilter(): void
    {
        $this->resetPage();
    }

    public function updatedMimeFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'modelFilter', 'collectionFilter', 'mimeFilter']);
        $this->resetPage();
        $this->success('Filters cleared.');
    }

    public function headers(): array
    {
        return [
            ['key' => 'preview', 'label' => '', 'sortable' => false],
            ['key' => 'name', 'label' => 'Name', 'sortable' => true],
            ['key' => 'model_type', 'label' => 'Type', 'sortable' => true, 'class' => 'hidden sm:table-cell'],
            ['key' => 'collection_name', 'label' => 'Collection', 'sortable' => true, 'class' => 'hidden sm:table-cell'],
            ['key' => 'size', 'label' => 'Size', 'sortable' => true, 'class' => 'hidden lg:table-cell'],
            ['key' => 'created_at', 'label' => 'Date', 'sortable' => true, 'class' => 'hidden sm:table-cell'],
        ];
    }

    public function media()
    {
        $query = Media::query()
            ->with(['model']);

        // Search filter
        if ($this->search) {
            $query->where(function ($q) {
                $q->where('name', 'ilike', '%' . $this->search . '%')
                    ->orWhere('file_name', 'ilike', '%' . $this->search . '%');
            });
        }

        // Model type filter
        if (! empty($this->modelFilter)) {
            $query->whereIn('model_type', $this->modelFilter);
        }

        // Collection filter
        if (! empty($this->collectionFilter)) {
            $query->whereIn('collection_name', $this->collectionFilter);
        }

        // MIME type filter
        if (! empty($this->mimeFilter)) {
            $query->whereIn('mime_type', $this->mimeFilter);
        }

        // Sorting
        $sortColumn = $this->sortBy['column'] ?? 'created_at';
        $sortDirection = $this->sortBy['direction'] ?? 'desc';
        $query->orderBy($sortColumn, $sortDirection);

        return $query->paginate($this->perPage);
    }

    public function modelTypes()
    {
        // Cache for 5 minutes to avoid repeated full table scans
        return Cache::remember('media:model_types', 300, function () {
            return Media::select('model_type')
                ->distinct()
                ->whereNotNull('model_type')
                ->pluck('model_type')
                ->map(fn ($type) => [
                    'id' => $type,
                    'name' => class_basename($type),
                ])
                ->toArray();
        });
    }

    public function collections()
    {
        // Cache for 5 minutes to avoid repeated full table scans
        return Cache::remember('media:collection_names', 300, function () {
            return Media::select('collection_name')
                ->distinct()
                ->whereNotNull('collection_name')
                ->pluck('collection_name')
                ->map(fn ($name) => [
                    'id' => $name,
                    'name' => ucfirst(str_replace('_', ' ', $name)),
                ])
                ->toArray();
        });
    }

    public function mimeTypes()
    {
        // Cache for 5 minutes to avoid repeated full table scans
        return Cache::remember('media:mime_types', 300, function () {
            return Media::select('mime_type')
                ->distinct()
                ->whereNotNull('mime_type')
                ->pluck('mime_type')
                ->map(fn ($type) => [
                    'id' => $type,
                    'name' => $type,
                ])
                ->toArray();
        });
    }

    public function bulkDelete(): void
    {
        if (empty($this->selectedItems)) {
            $this->error('No items selected for deletion.');

            return;
        }

        try {
            DB::transaction(function () {
                Media::whereIn('id', $this->selectedItems)->delete();
            });

            // Clear media filter caches after deletion
            $this->clearMediaFilterCaches();

            $count = count($this->selectedItems);
            $this->success("Successfully deleted {$count} media item(s).");
            $this->selectedItems = [];
            $this->resetPage();
        } catch (Exception $e) {
            $this->error('Failed to delete: ' . $e->getMessage());
        }
    }

    /**
     * Clear cached media filter values when media changes
     */
    private function clearMediaFilterCaches(): void
    {
        Cache::forget('media:model_types');
        Cache::forget('media:collection_names');
        Cache::forget('media:mime_types');
    }

    public function render()
    {
        return view('livewire.media.index', [
            'mediaItems' => $this->media(),
            'modelTypes' => $this->modelTypes(),
            'collections' => $this->collections(),
            'mimeTypes' => $this->mimeTypes(),
            'headers' => $this->headers(),
        ]);
    }
}
