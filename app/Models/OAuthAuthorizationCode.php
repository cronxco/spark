<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $user_id
 * @property string $code_hash
 * @property string $code_challenge
 * @property string $code_challenge_method
 * @property string $redirect_uri
 * @property string $client_id
 * @property string|null $device_name
 * @property string|null $scope
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 */
class OAuthAuthorizationCode extends Model
{
    protected $table = 'oauth_authorization_codes';

    protected $hidden = ['code_hash'];

    protected $fillable = [
        'user_id',
        'code_hash',
        'code_challenge',
        'code_challenge_method',
        'redirect_uri',
        'client_id',
        'device_name',
        'scope',
        'expires_at',
        'used_at',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Codes that are unexpired and unused.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeValid(Builder $query): Builder
    {
        return $query
            ->whereNull('used_at')
            ->where('expires_at', '>', now());
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }
}
