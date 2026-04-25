<?php

namespace App\Services;

use App\Models\LiveActivityToken;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Sends Live Activity start/update/end payloads directly to APNs over HTTP/2.
 * The Laravel notification-channels/apn package doesn't speak the
 * `liveactivity` push-type, so this service is our low-level escape hatch.
 */
class ApnsLiveActivityService
{
    public function __construct(protected HttpFactory $http) {}

    public function startOrUpdate(LiveActivityToken $token, array $contentState, array $alert = []): ?Response
    {
        return $this->send($token, 'update', $contentState, $alert);
    }

    public function end(LiveActivityToken $token, array $contentState = []): ?Response
    {
        return $this->send($token, 'end', $contentState);
    }

    protected function send(LiveActivityToken $token, string $event, array $contentState, array $alert = []): ?Response
    {
        $jwt = $this->jwt();
        if ($jwt === null) {
            Log::warning('ApnsLiveActivityService: APN key not configured; skipping push.');

            return null;
        }

        $aps = [
            'timestamp' => time(),
            'event' => $event,
            'content-state' => (object) $contentState,
        ];
        if ($alert !== []) {
            $aps['alert'] = $alert;
        }

        $payload = ['aps' => $aps];

        $bundleId = config('broadcasting.connections.apn.app_bundle_id', 'co.cronx.spark');
        $host = config('broadcasting.connections.apn.production')
            ? 'https://api.push.apple.com'
            : 'https://api.sandbox.push.apple.com';

        $response = $this->http->asJson()
            ->withOptions(['version' => '2.0'])
            ->withHeaders([
                'authorization' => 'bearer ' . $jwt,
                'apns-push-type' => 'liveactivity',
                'apns-topic' => $bundleId . '.push-type.liveactivity',
                'apns-priority' => '10',
                'apns-expiration' => (string) (time() + 3600),
            ])
            ->post($host . '/3/device/' . $token->push_token, $payload);

        if ($response->successful()) {
            $token->forceFill(['last_pushed_at' => now()])->save();
        } else {
            $body = $response->json();
            $reason = $body['reason'] ?? null;

            if (in_array($reason, ['Unregistered', 'BadDeviceToken', 'ExpiredProviderToken'], true) || $response->status() === 410) {
                Log::warning('ApnsLiveActivityService: Terminal error for token', [
                    'token_id' => $token->id,
                    'status' => $response->status(),
                    'reason' => $reason,
                ]);
            }
        }

        return $response;
    }

    protected function jwt(): ?string
    {
        $keyId = config('broadcasting.connections.apn.key_id');
        $teamId = config('broadcasting.connections.apn.team_id');
        $privateKeyPath = config('broadcasting.connections.apn.private_key_path');

        if (! $keyId || ! $teamId || ! $privateKeyPath || ! is_readable($privateKeyPath)) {
            return null;
        }

        return Cache::remember('apns:la:jwt', now()->addMinutes(55), function () use ($teamId, $privateKeyPath, $keyId) {
            return JWT::encode(
                ['iss' => $teamId, 'iat' => time()],
                file_get_contents($privateKeyPath),
                'ES256',
                $keyId,
            );
        });
    }
}
