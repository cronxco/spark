<?php

namespace App\Models;

use App\Services\Media\MediaDeduplicationService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Block extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia, LogsActivity, SoftDeletes;

    /**
     * Only record update events via LogsActivity trait.
     *
     * @var array<int, string>
     */
    protected static $recordEvents = ['updated'];

    public $incrementing = false;

    protected $table = 'blocks';

    protected $keyType = 'string';

    protected $fillable = [
        'event_id',
        'block_type',
        'time',
        'title',
        'metadata',
        'url',
        'media_url',
        'value',
        'value_multiplier',
        'value_unit',
        'embeddings',
    ];

    protected $casts = [
        'time' => 'datetime',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get validation rules for creating/updating blocks
     */
    public static function validationRules($eventId = null, $blockId = null): array
    {
        return [
            'event_id' => 'required|exists:events,id',
            'title' => [
                'required',
                'string',
                'max:255',
                Rule::unique('blocks')
                    ->where('event_id', $eventId)
                    ->where(function ($query) {
                        return $query->whereNull('deleted_at');
                    })
                    ->ignore($blockId),
            ],
            'block_type' => 'string|max:255',
            'time' => 'nullable|date',
            'value' => 'nullable|integer',
            'value_multiplier' => 'nullable|integer',
            'value_unit' => 'nullable|string|max:255',
        ];
    }

    /**
     * Create or update a block ensuring no duplicates per event + title + block_type
     */
    public static function updateOrCreateForEvent(string $eventId, array $attributes, array $values = []): self
    {
        $searchCriteria = [
            'event_id' => $eventId,
            'title' => $attributes['title'],
            'block_type' => $attributes['block_type'] ?? null,
        ];

        // Add whereNull for deleted_at to only consider active blocks
        $query = static::where($searchCriteria)
            ->whereNull('deleted_at');

        $existingBlock = $query->first();

        if ($existingBlock) {
            // Update the existing block
            $existingBlock->update(array_merge($attributes, $values));

            return $existingBlock;
        }

        // Create new block
        return static::create(array_merge($attributes, $values, ['event_id' => $eventId]));
    }

    /**
     * Get all block types that have custom card layouts defined
     *
     * @return array<string, bool> Map of block_type => has_custom_layout
     */
    public static function getBlockTypesWithCustomLayouts(): array
    {
        $blockTypes = static::select('block_type')
            ->distinct()
            ->whereNotNull('block_type')
            ->pluck('block_type')
            ->toArray();

        $layoutMap = [];
        foreach ($blockTypes as $blockType) {
            $layoutMap[$blockType] = view()->exists("blocks.types.{$blockType}");
        }

        return $layoutMap;
    }

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = Str::uuid();
            }
        });

        static::deleting(function ($model): void {
            // Handle media deletion with deduplication logic
            $deduplicationService = app(MediaDeduplicationService::class);

            foreach ($model->media as $media) {
                $deduplicationService->deleteMedia($media, forceDelete: false);
            }
        });

        static::deleted(function ($model): void {
            activity('changelog')
                ->performedOn($model)
                ->event('deleted')
                ->log('deleted');
        });

        static::restored(function ($model): void {
            activity('changelog')
                ->performedOn($model)
                ->event('restored')
                ->log('restored');
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('changelog')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }

    /**
     * Register media collections for Block.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('downloaded_images')
            ->useDisk(config('media-library.disk_name'));

        $this->addMediaCollection('downloaded_videos')
            ->useDisk(config('media-library.disk_name'));

        $this->addMediaCollection('downloaded_documents')
            ->useDisk(config('media-library.disk_name'));
    }

    /**
     * Register media conversions for Block.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumbnail')
            ->width(300)
            ->height(300)
            ->sharpen(10)
            ->nonQueued()
            ->performOnCollections('downloaded_images');

        $this->addMediaConversion('medium')
            ->width(800)
            ->keepOriginalImageFormat()
            ->performOnCollections('downloaded_images');

        $this->addMediaConversion('webp')
            ->width(800)
            ->format('webp')
            ->performOnCollections('downloaded_images');
    }

    public function event()
    {
        return $this->belongsTo(Event::class)->withTrashed();
    }

    /**
     * Polymorphic relationships where this block is the "from" entity.
     */
    public function relationshipsFrom()
    {
        return $this->morphMany(Relationship::class, 'from')->withTrashed();
    }

    /**
     * Polymorphic relationships where this block is the "to" entity.
     */
    public function relationshipsTo()
    {
        return $this->morphMany(Relationship::class, 'to')->withTrashed();
    }

    /**
     * Get all relationships for this block (both from and to).
     */
    public function relationships()
    {
        return Relationship::where(function ($query) {
            $query->where('from_type', self::class)
                ->where('from_id', $this->id);
        })->orWhere(function ($query) {
            $query->where('to_type', self::class)
                ->where('to_id', $this->id);
        })->withTrashed();
    }

    /**
     * Get all related EventObjects through relationships.
     *
     * @param  string|null  $type  Optional relationship type filter
     */
    public function relatedObjects(?string $type = null)
    {
        $query = EventObject::whereIn('id', function ($subQuery) use ($type) {
            $subQuery->select('from_id')
                ->from('relationships')
                ->where('from_type', EventObject::class)
                ->where('to_type', self::class)
                ->where('to_id', $this->id)
                ->when($type, fn ($q) => $q->where('type', $type))
                ->union(
                    DB::table('relationships')
                        ->select('to_id')
                        ->where('to_type', EventObject::class)
                        ->where('from_type', self::class)
                        ->where('from_id', $this->id)
                        ->when($type, fn ($q) => $q->where('type', $type))
                );
        });

        return $query;
    }

    /**
     * Get all related Events through relationships.
     *
     * @param  string|null  $type  Optional relationship type filter
     */
    public function relatedEvents(?string $type = null)
    {
        $query = Event::whereIn('id', function ($subQuery) use ($type) {
            $subQuery->select('from_id')
                ->from('relationships')
                ->where('from_type', Event::class)
                ->where('to_type', self::class)
                ->where('to_id', $this->id)
                ->when($type, fn ($q) => $q->where('type', $type))
                ->union(
                    DB::table('relationships')
                        ->select('to_id')
                        ->where('to_type', Event::class)
                        ->where('from_type', self::class)
                        ->where('from_id', $this->id)
                        ->when($type, fn ($q) => $q->where('type', $type))
                );
        });

        return $query;
    }

    /**
     * Get all related Blocks through relationships.
     *
     * @param  string|null  $type  Optional relationship type filter
     */
    public function relatedBlocks(?string $type = null)
    {
        $query = self::whereIn('id', function ($subQuery) use ($type) {
            $subQuery->select('from_id')
                ->from('relationships')
                ->where('from_type', self::class)
                ->where('to_type', self::class)
                ->where('to_id', $this->id)
                ->when($type, fn ($q) => $q->where('type', $type))
                ->union(
                    DB::table('relationships')
                        ->select('to_id')
                        ->where('to_type', self::class)
                        ->where('from_type', self::class)
                        ->where('from_id', $this->id)
                        ->when($type, fn ($q) => $q->where('type', $type))
                );
        });

        return $query;
    }

    /**
     * Get the formatted value considering the multiplier
     */
    public function getFormattedValueAttribute()
    {
        if ($this->value === null || $this->value_multiplier === null) {
            return $this->value;
        }

        if ($this->value_multiplier === 1) {
            return $this->value;
        }

        return $this->value / $this->value_multiplier;
    }

    /**
     * Check if a custom card layout exists for this block type
     */
    public function hasCustomCardLayout(): bool
    {
        return view()->exists("blocks.types.{$this->block_type}");
    }

    /**
     * Get the path to the custom card layout if it exists
     */
    public function getCustomCardLayoutPath(): ?string
    {
        $path = "blocks.types.{$this->block_type}";

        return view()->exists($path) ? $path : null;
    }

    /**
     * Get the markdown content from metadata
     */
    public function getContent(): ?string
    {
        return $this->metadata['content'] ?? null;
    }

    /**
     * Get the content rendered as HTML from markdown
     */
    public function getContentAsHtml(): ?string
    {
        $content = $this->getContent();

        if (empty($content)) {
            return null;
        }

        return Str::markdown($content);
    }

    /**
     * Set the content as markdown text in metadata
     */
    public function setContent(string $text): self
    {
        $metadata = $this->metadata ?? [];
        $metadata['content'] = $text;
        $this->metadata = $metadata;

        return $this;
    }

    /**
     * Check if this block has content
     */
    public function hasContent(): bool
    {
        return ! empty($this->getContent());
    }
}
