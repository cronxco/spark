<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Encrypts the secret leaves of a jsonb column, leaving its structure intact.
 *
 * ADR 0018 covers the connector credentials that live in their own columns;
 * these are the ones that live inside `auth_metadata` and `configuration`.
 * Encrypting the whole column would break the SQL JSON paths the application
 * relies on — `auth_metadata->gocardless_reference` in IntegrationController,
 * and the ten `configuration->migration_*` updates in the migration jobs — so
 * only the leaf values are encrypted and the JSON stays structurally valid.
 *
 * The cast is transparent, so no call site changes: the ~67 array reads across
 * the plugins keep working unmodified.
 *
 * @implements CastsAttributes<array<string, mixed>, array<string, mixed>>
 */
class EncryptedJsonSecrets implements CastsAttributes
{
    /**
     * Keys whose scalar value is a credential wherever it appears.
     *
     * Deliberately narrower than sensitive_log_keys(): that list is tuned for
     * logging, where over-redaction is free, and includes keys such as `key`,
     * `auth` and `server_url` that carry ordinary configuration here. No key
     * below appears in any SQL JSON path in the application.
     *
     * @var array<int, string>
     */
    public const SECRET_KEYS = [
        'access_token',
        'api_key',
        'api_token',
        'client_secret',
        'password',
        'refresh_token',
        'secret',
        'token',
        'webhook_secret',
    ];

    /**
     * Keys whose entire subtree is secret, whatever the inner keys are called.
     *
     * Fetch stores session cookies at auth_metadata.domains.{domain}.cookies as
     * arbitrary name => value pairs (CookieParser::formatForStorage), so name
     * matching alone cannot reach them.
     *
     * @var array<int, string>
     */
    public const SECRET_SUBTREES = ['cookies'];

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decoded = is_array($value) ? $value : json_decode((string) $value, true);

        if (! is_array($decoded)) {
            return null;
        }

        return $this->walk($decoded, false, $this->decryptLeaf(...));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>|string|null
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array|string|null
    {
        if ($value === null) {
            return [$key => null];
        }

        $decoded = is_array($value) ? $value : json_decode((string) $value, true);

        if (! is_array($decoded)) {
            return [$key => null];
        }

        return [$key => json_encode($this->walk($decoded, false, $this->encryptLeaf(...)))];
    }

    /**
     * Recurse the structure, applying $transform to every secret scalar.
     *
     * @param  array<mixed>  $data
     * @param  bool  $inheritedSecret  Whether an ancestor key marked this subtree secret
     * @param  callable(string): string  $transform
     * @return array<mixed>
     */
    private function walk(array $data, bool $inheritedSecret, callable $transform): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string) $key);
            $isSecretSubtree = $inheritedSecret || in_array($lowerKey, self::SECRET_SUBTREES, true);
            $isSecretLeaf = $isSecretSubtree || in_array($lowerKey, self::SECRET_KEYS, true);

            if (is_array($value)) {
                $result[$key] = $this->walk($value, $isSecretSubtree, $transform);

                continue;
            }

            $result[$key] = $isSecretLeaf && is_string($value) && $value !== ''
                ? $transform($value)
                : $value;
        }

        return $result;
    }

    private function encryptLeaf(string $value): string
    {
        return $this->isEncrypted($value) ? $value : Crypt::encryptString($value);
    }

    private function decryptLeaf(string $value): string
    {
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            // Rows written before this cast are still plaintext, and the
            // backfill is allowed to lag the deploy: return them as they are
            // rather than failing the read.
            return $value;
        }
    }

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
}
