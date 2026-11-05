<?php

namespace App\Livewire\Media;

use App\Support\OwnedMediaQuery;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Index extends Component
{
    use Toast, WithPagination;

    /** Columns the table may sort by; anything else falls back to created_at. */
    private const SORTABLE_COLUMNS = ['name', 'model_type', 'collection_name', 'size', 'created_at', 'mime_type'];

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
        // A file without an MD5 has no content identity, so it is its own
        // group: falling back to the row id stops every unhashed file from
        // collapsing into one "duplicate" row.
        $dedupeKey = "COALESCE(NULLIF(custom_properties->>'md5_hash', ''), id::text)";

        $ranked = Media::query()
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
                DB::raw("COUNT(*) OVER (PARTITION BY {$dedupeKey}) as instances_count"),
                DB::raw("ROW_NUMBER() OVER (PARTITION BY {$dedupeKey} ORDER BY created_at DESC, id DESC) as dedupe_rank"),
            ]);

        // Only ever show media owned by the authenticated user.
        $this->scopeToOwnedMedia($ranked);

        if ($this->search) {
            $ranked->where(function ($q) {
                $q->where('name', 'ilike', '%' . $this->search . '%')
                    ->orWhere('file_name', 'ilike', '%' . $this->search . '%');
            });
        }

        if (! empty($this->modelFilter)) {
            $ranked->whereIn('model_type', $this->modelFilter);
        }

        if (! empty($this->collectionFilter)) {
            $ranked->whereIn('collection_name', $this->collectionFilter);
        }

        if (! empty($this->mimeFilter)) {
            $ranked->whereIn('mime_type', $this->mimeFilter);
        }

        $sortColumn = in_array($this->sortBy['column'] ?? null, self::SORTABLE_COLUMNS, true)
            ? $this->sortBy['column']
            : 'created_at';
        $sortDirection = ($this->sortBy['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        // Keep the most recent file of each group, then sort and page in the
        // database rather than loading the whole library into memory.
        return Media::query()
            ->fromSub($ranked, 'media')
            ->where('dedupe_rank', 1)
            ->with(['model'])
            ->orderBy($sortColumn, $sortDirection)
            ->orderBy('id', $sortDirection)
            ->paginate($this->perPage);
    }

    public function modelTypes()
    {
        // Cache per-user for 5 minutes to avoid repeated full table scans
        return Cache::remember('media:model_types:' . Auth::id(), 300, function () {
            return $this->scopeToOwnedMedia(Media::query())
                ->select('model_type')
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
        // Cache per-user for 5 minutes to avoid repeated full table scans
        return Cache::remember('media:collection_names:' . Auth::id(), 300, function () {
            return $this->scopeToOwnedMedia(Media::query())
                ->select('collection_name')
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
        // Cache per-user for 5 minutes to avoid repeated full table scans
        return Cache::remember('media:mime_types:' . Auth::id(), 300, function () {
            return $this->scopeToOwnedMedia(Media::query())
                ->select('mime_type')
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
                // Scope to owned media so arbitrary ids can't delete another user's files.
                $this->scopeToOwnedMedia(Media::whereIn('id', $this->selectedItems))->delete();
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
     * Constrain a Media query to records whose parent model is owned by the
     * authenticated user. Media hangs off EventObject (user_id) and Block
     * (through its event's integration); anything else is excluded.
     */
    private function scopeToOwnedMedia(Builder $query): Builder
    {
        return OwnedMediaQuery::scope($query, Auth::id());
    }

    /**
     * Clear cached media filter values when media changes
     */
    private function clearMediaFilterCaches(): void
    {
        Cache::forget('media:model_types:' . Auth::id());
        Cache::forget('media:collection_names:' . Auth::id());
        Cache::forget('media:mime_types:' . Auth::id());
    }
}
