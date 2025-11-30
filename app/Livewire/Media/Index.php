<?php

namespace App\Livewire\Media;

use Exception;
use Illuminate\Pagination\LengthAwarePaginator;
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
        // First, get deduplicated media (one per MD5 hash)
        $deduplicatedQuery = Media::query()
            ->select([
                'id',
                'model_type',
                'model_id',
                'uuid',
                'collection_name',
                'name',
                'file_name',
                'mime_type',
                'disk',
                'conversions_disk',
                'size',
                'manipulations',
                'custom_properties',
                'generated_conversions',
                'responsive_images',
                'order_column',
                'created_at',
                'updated_at',
                DB::raw("custom_properties->>'md5_hash' as md5_hash"),
                DB::raw('COUNT(*) OVER (PARTITION BY custom_properties->>\'md5_hash\') as instances_count'),
            ])
            ->with(['model']);

        // Search filter
        if ($this->search) {
            $deduplicatedQuery->where(function ($q) {
                $q->where('name', 'ilike', '%' . $this->search . '%')
                    ->orWhere('file_name', 'ilike', '%' . $this->search . '%');
            });
        }

        // Model type filter
        if (! empty($this->modelFilter)) {
            $deduplicatedQuery->whereIn('model_type', $this->modelFilter);
        }

        // Collection filter
        if (! empty($this->collectionFilter)) {
            $deduplicatedQuery->whereIn('collection_name', $this->collectionFilter);
        }

        // MIME type filter
        if (! empty($this->mimeFilter)) {
            $deduplicatedQuery->whereIn('mime_type', $this->mimeFilter);
        }

        // Get all media with counts, then deduplicate by keeping only one per hash
        $allMedia = $deduplicatedQuery->get();

        // Group by MD5 hash and keep only the most recent one from each group
        $deduplicatedMedia = $allMedia->groupBy('md5_hash')->map(function ($group) {
            // Sort by created_at desc and take the first (most recent)
            return $group->sortByDesc('created_at')->first();
        })->values();

        // Apply sorting
        $sortColumn = $this->sortBy['column'] ?? 'created_at';
        $sortDirection = $this->sortBy['direction'] ?? 'desc';

        if ($sortDirection === 'desc') {
            $deduplicatedMedia = $deduplicatedMedia->sortByDesc($sortColumn)->values();
        } else {
            $deduplicatedMedia = $deduplicatedMedia->sortBy($sortColumn)->values();
        }

        // Manual pagination
        $currentPage = $this->getPage();
        $perPage = $this->perPage;
        $total = $deduplicatedMedia->count();
        $items = $deduplicatedMedia->slice(($currentPage - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $currentPage,
            ['path' => request()->url(), 'query' => request()->query()]
        );
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

    /**
     * Clear cached media filter values when media changes
     */
    private function clearMediaFilterCaches(): void
    {
        Cache::forget('media:model_types');
        Cache::forget('media:collection_names');
        Cache::forget('media:mime_types');
    }
}
