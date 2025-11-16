<?php

namespace App\Models;

use App\Services\Media\MediaDeduplicationService;
use ArrayAccess;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Tags\HasTags;

class EventObject extends Model implements HasMedia
{
    use HasFactory, HasTags, InteractsWithMedia, LogsActivity, SoftDeletes;

    /**
     * Only record update events via LogsActivity trait.
     *
     * @var array<int, string>
     */
    protected static $recordEvents = ['updated'];

    public $incrementing = false;

    protected $table = 'objects';

    protected $keyType = 'string';

    protected $fillable = [
        'time',
        'user_id',
        'concept',
        'type',
        'title',
        'content',
        'metadata',
        'url',
        'media_url',
        'embeddings',
    ];

    protected $casts = [
        'time' => 'datetime',
        'metadata' => 'array',
        'embeddings' => 'array', // You may need a custom cast for vector fields
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = Str::uuid();
            }

            // no-op: user_id must be provided by callers now
        });

        static::updating(function ($model) {
            // Prevent title and content updates on locked objects
            if ($model->isLocked()) {
                $original = $model->getOriginal();

                // Check if title or content are being changed
                if ($model->isDirty('title') && $model->title !== $original['title']) {
                    // Revert title to original value
                    $model->title = $original['title'];
                }

                if ($model->isDirty('content') && $model->content !== $original['content']) {
                    // Revert content to original value
                    $model->content = $original['content'];
                }
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
     * Register media collections for EventObject.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('screenshots')
            ->useDisk(config('media-library.disk_name'));

        $this->addMediaCollection('pdfs')
            ->useDisk(config('media-library.disk_name'));

        $this->addMediaCollection('downloaded_images')
            ->useDisk(config('media-library.disk_name'));

        $this->addMediaCollection('downloaded_videos')
            ->useDisk(config('media-library.disk_name'));

        $this->addMediaCollection('downloaded_documents')
            ->useDisk(config('media-library.disk_name'));
    }

    /**
     * Register media conversions for EventObject.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumbnail')
            ->width(300)
            ->height(300)
            ->sharpen(10)
            ->nonQueued()
            ->performOnCollections('screenshots', 'downloaded_images');

        $this->addMediaConversion('medium')
            ->width(800)
            ->keepOriginalImageFormat()
            ->performOnCollections('screenshots', 'downloaded_images');

        $this->addMediaConversion('webp')
            ->width(800)
            ->format('webp')
            ->performOnCollections('screenshots', 'downloaded_images');
    }

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function integration()
    {
        return $this->belongsTo(Integration::class)->withTrashed();
    }

    public function actorEvents()
    {
        return $this->hasMany(Event::class, 'actor_id')->withTrashed();
    }

    public function targetEvents()
    {
        return $this->hasMany(Event::class, 'target_id')->withTrashed();
    }

    public function events()
    {
        return $this->actorEvents()->union($this->targetEvents());
    }

    /**
     * Polymorphic relationships where this object is the "from" entity.
     */
    public function relationshipsFrom()
    {
        return $this->morphMany(Relationship::class, 'from')->withTrashed();
    }

    /**
     * Polymorphic relationships where this object is the "to" entity.
     */
    public function relationshipsTo()
    {
        return $this->morphMany(Relationship::class, 'to')->withTrashed();
    }

    /**
     * Get all relationships for this object (both from and to).
     */
    public function allRelationships()
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
        $query = Block::whereIn('id', function ($subQuery) use ($type) {
            $subQuery->select('from_id')
                ->from('relationships')
                ->where('from_type', Block::class)
                ->where('to_type', self::class)
                ->where('to_id', $this->id)
                ->when($type, fn ($q) => $q->where('type', $type))
                ->union(
                    DB::table('relationships')
                        ->select('to_id')
                        ->where('to_type', Block::class)
                        ->where('from_type', self::class)
                        ->where('from_id', $this->id)
                        ->when($type, fn ($q) => $q->where('type', $type))
                );
        });

        return $query;
    }

    /**
     * Override attachTags to log activity
     */
    public function attachTags(array|ArrayAccess|\Spatie\Tags\Tag $tags, ?string $type = null): static
    {
        $className = static::getTagClassName();

        $tagObjects = collect($className::findOrCreate($tags, $type));

        // Get currently attached tag IDs before syncing
        $existingTagIds = $this->tags()->pluck('id')->toArray();

        $this->tags()->syncWithoutDetaching($tagObjects->pluck('id')->toArray());

        // Log only newly attached tags
        foreach ($tagObjects as $tag) {
            if (! in_array($tag->id, $existingTagIds)) {
                $this->logTagActivity($tag, null, 'added');
            }
        }

        return $this;
    }

    /**
     * Override detachTags to log activity
     */
    public function detachTags(array|ArrayAccess $tags, ?string $type = null): static
    {
        $tags = static::convertToTags($tags, $type);

        collect($tags)
            ->filter()
            ->each(function (\Spatie\Tags\Tag $tag) {
                $this->tags()->detach($tag);
                // Log the tag removal
                $this->logTagActivity($tag, null, 'removed');
            });

        return $this;
    }

    /**
     * Scope for semantic search using vector embeddings with temporal weighting
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  array  $embedding  The query embedding vector
     * @param  float  $threshold  Maximum cosine distance (lower = more strict)
     * @param  int  $limit  Maximum number of results
     * @param  float  $temporalWeight  Temporal decay factor (0 = no boost, 0.01 = 1% per day)
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSemanticSearch($query, array $embedding, float $threshold = 1.0, int $limit = 20, float $temporalWeight = 0.01)
    {
        $embeddingString = '['.implode(',', $embedding).']';

        if ($temporalWeight > 0) {
            // Apply temporal weighting: recent objects get a small boost
            // Formula: similarity * (1 + (days_ago * temporal_weight))
            // Example: 7 days ago with 0.01 weight = similarity * 1.07 (7% penalty)
            return $query->selectRaw('
                *,
                (embeddings <=> ?) as similarity,
                EXTRACT(EPOCH FROM (NOW() - time)) / 86400 as days_ago,
                ((embeddings <=> ?) * (1 + (EXTRACT(EPOCH FROM (NOW() - time)) / 86400) * ?)) as weighted_similarity
            ', [$embeddingString, $embeddingString, $temporalWeight])
                ->whereNotNull('embeddings')
                ->whereNotNull('time') // Only apply temporal weighting if time exists
                ->whereRaw('(embeddings <=> ?) < ?', [$embeddingString, $threshold])
                ->orderBy('weighted_similarity', 'asc')
                ->limit($limit);
        }

        // No temporal weighting - use raw similarity
        return $query->selectRaw('*, (embeddings <=> ?) as similarity', [$embeddingString])
            ->whereNotNull('embeddings')
            ->whereRaw('(embeddings <=> ?) < ?', [$embeddingString, $threshold])
            ->orderBy('similarity', 'asc')
            ->limit($limit);
    }

    /**
     * Scope for hybrid search (semantic + metadata filters) with temporal weighting
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  array  $embedding  The query embedding vector
     * @param  array  $filters  Additional metadata filters (concept, type, user_id, etc.)
     * @param  float  $threshold  Maximum cosine distance
     * @param  int  $limit  Maximum number of results
     * @param  float  $temporalWeight  Temporal decay factor (0 = no boost, 0.01 = 1% per day)
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeHybridSearch($query, array $embedding, array $filters = [], float $threshold = 1.0, int $limit = 20, float $temporalWeight = 0.01)
    {
        // Start with semantic search
        $query = $query->semanticSearch($embedding, $threshold, $limit, $temporalWeight);

        // Apply metadata filters
        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (isset($filters['concept'])) {
            $query->where('concept', $filters['concept']);
        }

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['from_date'])) {
            $query->where('time', '>=', $filters['from_date']);
        }

        if (isset($filters['to_date'])) {
            $query->where('time', '<=', $filters['to_date']);
        }

        return $query;
    }

    /**
     * Get searchable text representation for embedding generation
     */
    public function getSearchableText(): string
    {
        $parts = array_filter([
            $this->concept,
            $this->type,
            $this->title,
            $this->content,
            $this->url,
        ]);

        return implode(' ', $parts);
    }

    /**
     * Check if this object is locked (prevents title/content updates)
     */
    public function isLocked(): bool
    {
        return $this->metadata['locked'] ?? false;
    }

    /**
     * Lock this object to prevent title and content updates
     */
    public function lock(): void
    {
        $metadata = $this->metadata ?? [];
        $metadata['locked'] = true;
        $metadata['locked_at'] = now()->toIso8601String();

        $this->updateQuietly(['metadata' => $metadata]);

        activity('changelog')
            ->performedOn($this)
            ->event('locked')
            ->log('locked object');
    }

    /**
     * Unlock this object to allow title and content updates
     */
    public function unlock(): void
    {
        $metadata = $this->metadata ?? [];
        $metadata['locked'] = false;

        $this->updateQuietly(['metadata' => $metadata]);

        activity('changelog')
            ->performedOn($this)
            ->event('unlocked')
            ->log('unlocked object');
    }

    /**
     * Log tag activity to the activity log
     */
    private function logTagActivity(string|\Spatie\Tags\Tag $tag, ?string $type, string $action): void
    {
        // Determine tag name and type
        if ($tag instanceof \Spatie\Tags\Tag) {
            $tagName = $tag->name;
            $tagType = $tag->type;
        } else {
            $tagName = $tag;
            $tagType = $type;
        }

        // Format tag label
        $tagLabel = $tagType && $tagType !== 'spark' && $tagType !== 'emoji'
            ? "{$tagType}:{$tagName}"
            : $tagName;

        // Log to activity log
        activity('changelog')
            ->performedOn($this)
            ->event("tag_{$action}")
            ->withProperties(['tag' => $tagLabel, 'tag_name' => $tagName, 'tag_type' => $tagType])
            ->log("{$action} tag \"{$tagLabel}\"");
    }
}
