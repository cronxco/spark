<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'settings',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Boot the model and set the ID to a UUID on creation.
     */
    public static function booted()
    {
        static::creating(function ($model) {
            $model->id = Str::uuid();
        });
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }

    /**
     * Get the user's integrations
     */
    public function integrations()
    {
        return $this->hasMany(Integration::class);
    }

    public function integrationGroups()
    {
        return $this->hasMany(IntegrationGroup::class);
    }

    /**
     * Get the user's events
     */
    public function events()
    {
        return $this->hasMany(Event::class);
    }

    /**
     * Get the user's sessions
     */
    public function sessions()
    {
        return $this->hasMany(Session::class)->orderBy('last_activity', 'desc');
    }

    /**
     * Check if debug logging is enabled for this user
     */
    public function hasDebugLoggingEnabled(): bool
    {
        $settings = $this->settings ?? [];

        // If user has explicitly set preference, use it
        if (isset($settings['debug_logging_enabled'])) {
            return (bool) $settings['debug_logging_enabled'];
        }

        // Otherwise fall back to environment variable (default true if not set)
        return config('logging.debug_logging_default', true);
    }

    /**
     * Enable debug logging for this user
     */
    public function enableDebugLogging(): void
    {
        $settings = $this->settings ?? [];
        $settings['debug_logging_enabled'] = true;
        $this->update(['settings' => $settings]);
    }

    /**
     * Disable debug logging for this user
     */
    public function disableDebugLogging(): void
    {
        $settings = $this->settings ?? [];
        $settings['debug_logging_enabled'] = false;
        $this->update(['settings' => $settings]);
    }

    /**
     * Get the first block of the user's UUID for log filenames
     */
    public function getUuidBlock(): string
    {
        return explode('-', $this->id)[0] ?? $this->id;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'settings' => 'array',
        ];
    }
}
