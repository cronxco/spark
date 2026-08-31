<?php

namespace App\Services\Flint;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;
use RuntimeException;

class FlintRunToken
{
    /** @param array<string, mixed> $claims */
    public function issue(array $claims): string
    {
        $claims['expires_at'] ??= now()->addDay()->timestamp;

        return Crypt::encryptString(json_encode($claims, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function verify(string $token, User $user, string $date, string $period): array
    {
        try {
            $claims = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException $exception) {
            throw new RuntimeException('The Flint run token is invalid.', previous: $exception);
        }

        if (! is_array($claims)
            || ($claims['user_id'] ?? null) !== (string) $user->id
            || ($claims['local_date'] ?? null) !== $date
            || ($claims['period'] ?? null) !== $period
            || (int) ($claims['expires_at'] ?? 0) < now()->timestamp
            || ! is_string($claims['run_uuid'] ?? null)
        ) {
            throw new RuntimeException('The Flint run token does not match this digest.');
        }

        return $claims;
    }
}
