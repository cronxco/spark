<?php

namespace App\Integrations\Base;

use App\Integrations\Contracts\OAuthIntegrationPlugin;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;
use Throwable;

abstract class OAuthPlugin implements OAuthIntegrationPlugin
{
    protected string $baseUrl;
    protected string $clientId;
    protected string $clientSecret;
    protected string $redirectUri;

    public static function getServiceType(): string
    {
        return 'oauth';
    }

    /**
     * Back-compat: old method signature created an instance immediately.
     * We now create a group first. This helper returns a placeholder instance
     * bound to the group so existing flows that expect an Integration still work
     * until callers migrate to initializeGroup + onboarding.
     */
    public function initialize(User $user): Integration
    {
        $group = $this->initializeGroup($user);

        return Integration::create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => static::getIdentifier(),
            'name' => static::getDisplayName(),
            'instance_type' => null,
            'configuration' => [],
        ]);
    }

    public function initializeGroup(User $user): IntegrationGroup
    {
        return IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => static::getIdentifier(),
            'account_id' => null,
            'access_token' => null,
            'refresh_token' => null,
            'expiry' => null,
            'refresh_expiry' => null,
        ]);
    }

    public function createInstance(IntegrationGroup $group, string $instanceType, array $initialConfig = []): Integration
    {
        // Derive a sensible default name from plugin instance types if available
        $defaultName = static::getDisplayName();
        if (method_exists(static::class, 'getInstanceTypes')) {
            try {
                $types = static::getInstanceTypes();
                $defaultName = $types[$instanceType]['label'] ?? ucfirst($instanceType);
            } catch (Throwable $e) {
                $defaultName = ucfirst($instanceType);
            }
        } else {
            $defaultName = ucfirst($instanceType);
        }

        return Integration::create([
            'user_id' => $group->user_id,
            'integration_group_id' => $group->id,
            'service' => static::getIdentifier(),
            'name' => $defaultName,
            'instance_type' => $instanceType,
            'configuration' => $initialConfig,
        ]);
    }

    public function getOAuthUrl(IntegrationGroup $group): string
    {
        // Generate PKCE code verifier and challenge
        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->generateCodeChallenge($codeVerifier);

        // Generate CSRF token
        $csrfToken = Str::random(32);

        // Store CSRF token in session for validation
        $sessionKey = 'oauth_csrf_' . session_id() . '_' . $group->id;
        Session::put($sessionKey, $csrfToken);

        $state = encrypt([
            'group_id' => $group->id,
            'user_id' => $group->user_id,
            'csrf_token' => $csrfToken,
            'code_verifier' => $codeVerifier,
        ]);

        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => $this->getRequiredScopes(),
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];

        return $this->baseUrl . '/oauth/authorize?' . http_build_query($params);
    }

    public function handleOAuthCallback(Request $request, IntegrationGroup $group): void
    {
        $code = $request->get('code');
        $state = $request->get('state');

        // Verify state
        $stateData = decrypt($state);
        if ((string) ($stateData['group_id'] ?? '') !== (string) $group->id) {
            throw new Exception('Invalid state parameter');
        }

        // Validate CSRF token
        if (! isset($stateData['csrf_token']) || ! $this->validateCsrfToken($stateData['csrf_token'], $group)) {
            throw new Exception('Invalid CSRF token');
        }

        // Get code verifier from state
        $codeVerifier = $stateData['code_verifier'] ?? null;
        if (! $codeVerifier) {
            throw new Exception('Missing code verifier');
        }

        // Exchange code for tokens with PKCE
        $hub = SentrySdk::getCurrentHub();
        $parentSpan = $hub->getSpan();
        $span = $parentSpan?->startChild((new SpanContext)->setOp('http.client')->setDescription('POST ' . $this->baseUrl . '/oauth/token'));
        $response = Http::post($this->baseUrl . '/oauth/token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri,
            'code_verifier' => $codeVerifier,
        ]);
        $span?->finish();

        if (! $response->successful()) {
            throw new Exception('Failed to exchange code for tokens');
        }

        $tokenData = $response->json();

        // Update group with tokens
        $group->update([
            'access_token' => $tokenData['access_token'],
            'refresh_token' => $tokenData['refresh_token'] ?? null,
            'expiry' => isset($tokenData['expires_in'])
                ? now()->addSeconds($tokenData['expires_in'])
                : null,
        ]);

        // Fetch account information
        $this->fetchAccountInfoForGroup($group);
    }

    public function handleWebhook(Request $request, Integration $integration): void
    {
        // OAuth plugins don't handle webhooks
        throw new Exception('OAuth plugins do not handle webhooks');
    }

    protected function refreshToken(IntegrationGroup $group): void
    {
        $hub = SentrySdk::getCurrentHub();
        $parentSpan = $hub->getSpan();
        $span = $parentSpan?->startChild((new SpanContext)->setOp('http.client')->setDescription('POST ' . $this->baseUrl . '/oauth/token'));
        $response = Http::post($this->baseUrl . '/oauth/token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $group->refresh_token,
            'grant_type' => 'refresh_token',
        ]);
        $span?->finish();

        if (! $response->successful()) {
            throw new Exception('Failed to refresh token');
        }

        $tokenData = $response->json();

        $group->update([
            'access_token' => $tokenData['access_token'],
            'refresh_token' => $tokenData['refresh_token'] ?? $group->refresh_token,
            'expiry' => isset($tokenData['expires_in'])
                ? now()->addSeconds($tokenData['expires_in'])
                : null,
        ]);
    }

    protected function makeAuthenticatedRequest(string $endpoint, Integration $integration): array
    {
        // Prefer group token if available
        $group = $integration->group;
        $token = $integration->access_token; // legacy fallback only
        if ($group) {
            if ($group->expiry && $group->expiry->isPast()) {
                $this->refreshToken($group);
            }
            $token = $group->access_token;
        }

        if (empty($token)) {
            throw new Exception('Missing access token for authenticated request');
        }

        $hub = SentrySdk::getCurrentHub();
        $parentSpan = $hub->getSpan();
        $span = $parentSpan?->startChild((new SpanContext)->setOp('http.client')->setDescription('GET ' . $this->baseUrl . $endpoint));
        $response = Http::withToken($token)
            ->get($this->baseUrl . $endpoint);
        $span?->finish();

        if (! $response->successful()) {
            throw new Exception('API request failed: ' . $response->body());
        }

        return $response->json();
    }

    abstract protected function getRequiredScopes(): string;

    abstract protected function fetchAccountInfoForGroup(IntegrationGroup $group): void;

    /**
     * Generate a PKCE code verifier
     */
    protected function generateCodeVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Generate a PKCE code challenge from a code verifier
     */
    protected function generateCodeChallenge(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }

    /**
     * Validate CSRF token against stored session value
     */
    protected function validateCsrfToken(string $token, IntegrationGroup $group): bool
    {
        // Get the session key for this group
        $sessionKey = 'oauth_csrf_' . session_id() . '_' . $group->id;

        // Retrieve stored token from session
        $storedToken = Session::get($sessionKey);

        if (! $storedToken) {
            return false; // No stored token found
        }

        // Compare tokens
        $isValid = hash_equals($storedToken, $token);

        // Remove the token from session after validation (one-time use)
        if ($isValid) {
            Session::forget($sessionKey);
        }

        return $isValid;
    }
}
