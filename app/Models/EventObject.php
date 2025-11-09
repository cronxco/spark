<?php

namespace App\Models;

use ArrayAccess;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Tags\HasTags;

class EventObject extends Model
{
    use HasFactory, HasTags, LogsActivity, SoftDeletes;

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
