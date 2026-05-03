<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\OAuthAuthorizationCode;
use App\Models\OAuthRefreshToken;
use App\Models\User;
use App\Support\Pkce;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OAuthController extends Controller
{
    public const IOS_CLIENT_ID = 'ios';

    public const IOS_REDIRECT_URI = 'spark://auth/callback';

    public const CODE_TTL_MINUTES = 10;

    public const ACCESS_TOKEN_TTL_MINUTES = 60;

    public const REFRESH_TOKEN_TTL_DAYS = 60;

    /**
     * Seconds after a rotation within which a replay is treated as a network
     * race condition rather than token theft. Keeps the user's current tokens
     * alive instead of doing a full device revocation.
     */
    public const REPLAY_GRACE_SECONDS = 30;

    /**
     * Show the consent screen for an OAuth PKCE authorization request.
     */
    public function authorize(Request $request): View|RedirectResponse
    {
        $params = $this->validateAuthorizeRequest($request);

        return view('auth.oauth-consent', [
            'client_id' => $params['client_id'],
            'redirect_uri' => $params['redirect_uri'],
            'response_type' => $params['response_type'],
            'code_challenge' => $params['code_challenge'],
            'code_challenge_method' => $params['code_challenge_method'],
            'state' => $params['state'],
            'device_name' => $params['device_name'] ?? null,
            'scope' => $params['scope'] ?? 'ios:*',
        ]);
    }

    /**
     * Approve the authorization request: issue a single-use auth code and redirect back to the client.
     */
    public function approve(Request $request): RedirectResponse
    {
        $params = $this->validateAuthorizeRequest($request);

        $plainCode = Str::random(64);

        OAuthAuthorizationCode::create([
            'user_id' => $request->user()->getKey(),
            'code_hash' => hash('sha256', $plainCode),
            'code_challenge' => $params['code_challenge'],
            'code_challenge_method' => $params['code_challenge_method'],
            'redirect_uri' => $params['redirect_uri'],
            'client_id' => $params['client_id'],
            'device_name' => $params['device_name'] ?? null,
            'scope' => $params['scope'] ?? 'ios:*',
            'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
        ]);

        $callback = $params['redirect_uri'] . '?' . http_build_query([
            'code' => $plainCode,
            'state' => $params['state'],
        ]);

        return redirect()->away($callback);
    }

    /**
     * Deny the authorization request: redirect back to the client with error=access_denied.
     */
    public function deny(Request $request): RedirectResponse
    {
        $params = $this->validateAuthorizeRequest($request);

        $callback = $params['redirect_uri'] . '?' . http_build_query([
            'error' => 'access_denied',
            'error_description' => 'The user denied the authorization request.',
            'state' => $params['state'],
        ]);

        return redirect()->away($callback);
    }

    /**
     * Exchange an authorization code (+ PKCE verifier) for access + refresh tokens.
     */
    public function token(Request $request): JsonResponse
    {
        $data = $request->validate([
            'grant_type' => ['required', 'string', 'in:authorization_code'],
            'code' => ['required', 'string'],
            'code_verifier' => ['required', 'string', 'min:43', 'max:128'],
            'client_id' => ['required', 'string', 'in:' . self::IOS_CLIENT_ID],
            'redirect_uri' => ['required', 'string', 'in:' . self::IOS_REDIRECT_URI],
        ]);

        $codeHash = hash('sha256', $data['code']);

        return DB::transaction(function () use ($data, $codeHash): JsonResponse {
            $authCode = OAuthAuthorizationCode::query()
                ->where('code_hash', $codeHash)
                ->lockForUpdate()
                ->first();

            if (! $authCode || $authCode->used_at !== null || $authCode->expires_at->isPast()) {
                return $this->oauthError('invalid_grant', 'The authorization code is invalid, used, or expired.', 400);
            }

            if ($authCode->client_id !== $data['client_id'] || $authCode->redirect_uri !== $data['redirect_uri']) {
                return $this->oauthError('invalid_grant', 'Client or redirect URI mismatch.', 400);
            }

            if (! Pkce::verifyChallenge($data['code_verifier'], $authCode->code_challenge, $authCode->code_challenge_method)) {
                return $this->oauthError('invalid_grant', 'PKCE verification failed.', 400);
            }

            $authCode->update(['used_at' => now()]);

            /** @var User $user */
            $user = User::query()->findOrFail($authCode->user_id);

            return response()->json($this->issueTokens(
                $user,
                $authCode->client_id,
                $authCode->device_name,
                $authCode->scope ?? 'ios:*',
            ));
        });
    }

    /**
     * Rotate a refresh token: revoke the old one, issue new access + refresh tokens.
     * Replay of an already-revoked refresh token revokes ALL tokens for that device.
     */
    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate([
            'grant_type' => ['required', 'string', 'in:refresh_token'],
            'refresh_token' => ['required', 'string'],
            'client_id' => ['required', 'string', 'in:' . self::IOS_CLIENT_ID],
        ]);

        $tokenHash = hash('sha256', $data['refresh_token']);

        return DB::transaction(function () use ($data, $tokenHash): JsonResponse {
            $refresh = OAuthRefreshToken::query()
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if (! $refresh) {
                return $this->oauthError('invalid_grant', 'Refresh token not recognized.', 400);
            }

            if ($refresh->revoked_at !== null) {
                // If the token was rotated very recently it is almost certainly a
                // server-restart race condition: the rotation completed but the
                // response never reached the client, so it retried with the old
                // token. In that window we return 401 without touching other tokens
                // so the user just has to re-authenticate once.
                // Outside the grace period we assume actual token theft and nuke
                // every token for the device.
                if ($refresh->revoked_at->lt(now()->subSeconds(self::REPLAY_GRACE_SECONDS))) {
                    $this->revokeDeviceTokens($refresh);

                    return $this->oauthError('invalid_grant', 'Refresh token already used; all device tokens revoked.', 401);
                }

                return $this->oauthError('invalid_grant', 'Refresh token already used.', 401);
            }

            if ($refresh->expires_at->isPast()) {
                return $this->oauthError('invalid_grant', 'Refresh token expired.', 400);
            }

            if ($refresh->client_id !== $data['client_id']) {
                return $this->oauthError('invalid_grant', 'Client mismatch.', 400);
            }

            $refresh->update(['revoked_at' => now()]);

            // Revoke the paired access token if it still exists.
            if ($refresh->access_token_id !== null) {
                User::query()->find($refresh->user_id)?->tokens()->whereKey($refresh->access_token_id)->delete();
            }

            /** @var User $user */
            $user = User::query()->findOrFail($refresh->user_id);

            return response()->json($this->issueTokens(
                $user,
                $refresh->client_id,
                $refresh->device_name,
                $refresh->scope ?? 'ios:*',
            ));
        });
    }

    /**
     * Issue a Sanctum personal access token + paired refresh token.
     *
     * @return array{access_token:string,token_type:string,expires_in:int,refresh_token:string,scope:string}
     */
    protected function issueTokens(User $user, string $clientId, ?string $deviceName, string $scope): array
    {
        $abilities = $this->scopeToAbilities($scope);
        $tokenName = $deviceName ?: $clientId;

        $accessToken = $user->createToken(
            $tokenName,
            $abilities,
            now()->addMinutes(self::ACCESS_TOKEN_TTL_MINUTES),
        );

        $plainRefresh = Str::random(80);

        OAuthRefreshToken::create([
            'user_id' => $user->getKey(),
            'token_hash' => hash('sha256', $plainRefresh),
            'access_token_id' => $accessToken->accessToken->getKey(),
            'client_id' => $clientId,
            'device_name' => $deviceName,
            'scope' => $scope,
            'expires_at' => now()->addDays(self::REFRESH_TOKEN_TTL_DAYS),
        ]);

        return [
            'access_token' => $accessToken->plainTextToken,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TOKEN_TTL_MINUTES * 60,
            'refresh_token' => $plainRefresh,
            'scope' => $scope,
        ];
    }

    /**
     * Revoke every Sanctum token + refresh token for the device the given refresh token belonged to.
     */
    protected function revokeDeviceTokens(OAuthRefreshToken $refresh): void
    {
        $user = User::query()->find($refresh->user_id);

        if (! $user) {
            return;
        }

        $tokenName = $refresh->device_name ?: $refresh->client_id;

        $user->tokens()->where('name', $tokenName)->delete();

        OAuthRefreshToken::query()
            ->where('user_id', $refresh->user_id)
            ->where('client_id', $refresh->client_id)
            ->when(
                $refresh->device_name !== null,
                fn ($q) => $q->where('device_name', $refresh->device_name),
                fn ($q) => $q->whereNull('device_name'),
            )
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    /**
     * Translate an OAuth scope string into Sanctum token abilities.
     *
     * Sanctum ability checks are exact string matches (not glob patterns),
     * so `ios:*` in a scope string expands to the explicit pair that the
     * `ability:ios:read` / `ability:ios:write` middleware actually look for.
     *
     * @return array<int, string>
     */
    protected function scopeToAbilities(string $scope): array
    {
        $allowed = ['ios:read', 'ios:write', 'ios:*'];

        $tokens = array_values(array_filter(explode(' ', trim($scope))));
        $abilities = array_values(array_intersect($tokens, $allowed));

        if ($abilities === [] || in_array('ios:*', $abilities, true)) {
            return ['ios:read', 'ios:write'];
        }

        return $abilities;
    }

    /**
     * Validate the authorize request parameters (shared between GET and POST).
     *
     * @return array{client_id:string,redirect_uri:string,response_type:string,code_challenge:string,code_challenge_method:string,state:string,device_name?:string,scope?:string}
     */
    protected function validateAuthorizeRequest(Request $request): array
    {
        return $request->validate([
            'client_id' => ['required', 'string', 'in:' . self::IOS_CLIENT_ID],
            'redirect_uri' => ['required', 'string', 'in:' . self::IOS_REDIRECT_URI],
            'response_type' => ['required', 'string', 'in:code'],
            'code_challenge' => ['required', 'string'],
            'code_challenge_method' => ['required', 'string', 'in:' . Pkce::METHOD_S256],
            'state' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'scope' => ['nullable', 'string', 'max:255'],
        ]);
    }

    protected function oauthError(string $code, string $description, int $status): JsonResponse
    {
        return response()->json([
            'error' => $code,
            'error_description' => $description,
        ], $status);
    }
}
