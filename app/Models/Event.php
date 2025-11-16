<?php

namespace App\Models;

use App\Integrations\PluginRegistry;
use App\Jobs\Metrics\DetectMetricAnomaliesJob;
use ArrayAccess;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Tags\HasTags;

class Event extends Model
{
    use HasFactory, HasTags, LogsActivity, SoftDeletes;

    /**
     * Only record update events via LogsActivity trait
     * to avoid duplicating data on create/delete.
     *
     * @var array<int, string>
     */
    protected static $recordEvents = ['updated'];

    public $incrementing = false;

    protected $table = 'events';

    protected $keyType = 'string';

    protected $fillable = [
        'source_id',
        'time',
        'integration_id',
        'actor_id',
        'actor_metadata',
        'service',
        'domain',
        'action',
        'value',
        'value_multiplier',
        'value_unit',
        'event_metadata',
        'target_id',
        'target_metadata',
        'embeddings',
    ];

    protected $casts = [
        'time' => 'datetime',
        'actor_metadata' => 'array',
        'event_metadata' => 'array',
        'target_metadata' => 'array',
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
        });

        static::created(function ($model): void {
            // Check if anomaly detection should run in realtime for this integration
            $shouldRunRealtime = true;

            if ($model->integration) {
                $plugin = PluginRegistry::getPlugin($model->service);
                if ($plugin) {
                    $instanceTypes = $plugin::getInstanceTypes();
                    $instanceType = $model->integration->instance_type;

                    if (isset($instanceTypes[$instanceType]['anomaly_detection_mode'])) {
                        $mode = $instanceTypes[$instanceType]['anomaly_detection_mode'];
                        // Only dispatch for realtime mode, skip for retrospective and disabled
                        $shouldRunRealtime = $mode === 'realtime';
                    }
                }
            }

            // Dispatch anomaly detection job only if mode is realtime
            if ($shouldRunRealtime) {
                DetectMetricAnomaliesJob::dispatch($model);
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

    public function integration()
    {
        return $this->belongsTo(Integration::class)->withTrashed();
    }

    public function actor()
    {
        return $this->belongsTo(EventObject::class, 'actor_id')->withTrashed();
    }

    public function target()
    {
        return $this->belongsTo(EventObject::class, 'target_id')->withTrashed();
    }

    public function blocks()
    {
        return $this->hasMany(Block::class)->withTrashed();
    }

    /**
     * Create or update a block for this event, ensuring no duplicates
     * based on title and block_type combination.
     *
     * ✅ PREFERRED METHOD: This method prevents duplicate blocks and updates existing ones.
     * ❌ DO NOT USE: $event->blocks()->create() - This creates duplicates!
     *
     * @param  array  $blockData  Block data including:
     *                            - string $title (required) - Block title, used for uniqueness
     *                            - string $block_type (optional) - Block category, used for uniqueness
     *                            - mixed $value (optional) - Numeric value
     *                            - int $value_multiplier (optional) - Value multiplier, defaults to 1
     *                            - string $value_unit (optional) - Value unit (e.g., 'bpm', 'kcal')
     *                            - array $metadata (optional) - Additional metadata
     *                            - string $url (optional) - Related URL
     *                            - string $media_url (optional) - Media/image URL
     *                            - mixed $embeddings (optional) - Vector embeddings
     *                            - string $time (optional) - Block timestamp, defaults to event time
     * @return Block The created or updated block
     *
     * @example
     * // Create a heart rate block
     * $event->createBlock([
     *     'title' => 'Average Heart Rate',
     *     'block_type' => 'heart_rate',
     *     'value' => 75,
     *     'value_unit' => 'bpm',
     *     'metadata' => ['type' => 'average'],
     * ]);
     */
    public function createBlock(array $blockData): Block
    {
        return Block::updateOrCreateForEvent($this->id, $blockData);
    }

    /**
     * Get the formatted value considering the multiplier
     */
    public function getFormattedValueAttribute()
    {
        if ($this->value === null || $this->value_multiplier === null) {
            return $this->value;
        }

        if ($this->value_multiplier === 1 || $this->value_multiplier === 0) {
            return $this->value;
        }

        return $this->value / $this->value_multiplier;
    }

    /**
     * Get related objects through direct relationships (actor/target)
     * This maintains compatibility with the original simple design
     */
    public function objects()
    {
        return new class($this)
        {
            private $event;

            public function __construct($event)
            {
                $this->event = $event;
            }

            public function syncWithoutDetaching($relationships)
            {
                // For backward compatibility, just return true
                // The original design didn't need complex object relationships
                return true;
            }

            public function get()
            {
                $objects = collect();

                // Return actor and target objects
                if ($this->event->actor) {
                    $this->event->actor->pivot = (object) ['role' => 'actor'];
                    $objects->push($this->event->actor);
                }

                if ($this->event->target) {
                    $this->event->target->pivot = (object) ['role' => 'target'];
                    $objects->push($this->event->target);
                }

                return $objects;
            }
        };
    }

    /**
     * Polymorphic relationships where this event is the "from" entity.
     */
    public function relationshipsFrom()
    {
        return $this->morphMany(Relationship::class, 'from')->withTrashed();
    }

    /**
     * Polymorphic relationships where this event is the "to" entity.
     */
    public function relationshipsTo()
    {
        return $this->morphMany(Relationship::class, 'to')->withTrashed();
    }

    /**
     * Get all relationships for this event (both from and to).
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
