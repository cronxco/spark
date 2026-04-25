<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use NotificationChannels\WebPush\PushSubscription as BasePushSubscription;

/**
 * @property string $device_type
 * @property string|null $app_environment
 * @property string|null $bundle_id
 * @property string|null $app_version
 * @property string|null $os_version
 */
class PushSubscription extends BasePushSubscription
{
    public const DEVICE_TYPE_WEB = 'web';

    public const DEVICE_TYPE_IOS = 'ios';

    protected $fillable = [
        'endpoint',
        'public_key',
        'auth_token',
        'content_encoding',
        'device_type',
        'app_environment',
        'bundle_id',
        'app_version',
        'os_version',
    ];

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeApns(Builder $query): Builder
    {
        return $query->where('device_type', self::DEVICE_TYPE_IOS);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeDeviceType(Builder $query, string $type): Builder
    {
        return $query->where('device_type', $type);
    }
}
