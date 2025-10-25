<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Block extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

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

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = Str::uuid();
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

    public function event()
    {
        return $this->belongsTo(Event::class)->withTrashed();
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
}
