<?php

namespace App\Integrations\Base;

use App\Integrations\Contracts\OAuthIntegrationPlugin;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use App\Notifications\IntegrationAuthenticationFailed;
use App\Support\Pkce;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    public static function supportsMigration(): bool
    {
        return false;
    }

    /**
     * OAuth integrations use polling, not staleness checking
     */
    public static function getTimeUntilStaleMinutes(): ?int
    {
        return null;
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

    public function createInstance(IntegrationGroup $group, string $instanceType, array $initialConfig = [], bool $withMigration = false): Integration
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

        // If creating with migration, pause the integration by default
        $config = $initialConfig;
        if ($withMigration && static::supportsMigration()) {
            $config['paused'] = true;
        }

        return Integration::create([
            'user_id' => $group->user_id,
            'integration_group_id' => $group->id,
            'service' => static::getIdentifier(),
            'name' => $defaultName,
            'instance_type' => $instanceType,
            'configuration' => $config,
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

        // Log the API request
        $this->logApiRequest('POST', '/oauth/token', [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], [
            'client_id' => $this->clientId,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri,
            'code_verifier' => '[REDACTED]', // PKCE code verifier
        ]);

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

        // Log the API response
        $this->logApiResponse('POST', '/oauth/token', $response->status(), $response->body(), $response->headers());

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

    /**
     * Log API request details for debugging
     */
    public function logApiRequest(string $method, string $endpoint, array $headers = [], array $data = [], ?string $integrationId = null): void
    {
        log_integration_api_request(
            static::getIdentifier(),
            $method,
            $endpoint,
            $this->sanitizeHeaders($headers),
            $this->sanitizeData($data),
            $integrationId ?: '',
            true // Use per-instance logging
        );
    }

    /**
     * Log API response details for debugging
     */
    public function logApiResponse(string $method, string $endpoint, int $statusCode, string $body, array $headers = [], ?string $integrationId = null): void
    {
        log_integration_api_response(
            static::getIdentifier(),
            $method,
            $endpoint,
            $statusCode,
            $this->sanitizeResponseBody($body),
            $this->sanitizeHeaders($headers),
            $integrationId ?: '',
            true // Use per-instance logging
        );
    }

    protected function refreshToken(IntegrationGroup $group): void
    {
        // Log the API request
        $this->logApiRequest('POST', '/oauth/token', [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], [
            'client_id' => $this->clientId,
            'grant_type' => 'refresh_token',
        ]);

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

        // Log the API response
        $this->logApiResponse('POST', '/oauth/token', $response->status(), $response->body(), $response->headers());

        if (! $response->successful()) {
            // Check if this is an authentication failure (invalid refresh token)
            // These typically return 400 or 401 with error codes like 'invalid_grant'
            if (in_array($response->status(), [400, 401])) {
                $errorData = $response->json();
                $errorCode = $errorData['error'] ?? '';

                // invalid_grant means the refresh token is no longer valid - user must re-auth
                if ($errorCode === 'invalid_grant' || $response->status() === 401) {
                    // Send notification to user - they need to re-authorize
                    try {
                        // Get any integration from this group to use in the notification
                        $integration = $group->integrations()->first();
                        if ($integration) {
                            $group->user->notify(
                                new IntegrationAuthenticationFailed(
                                    $integration,
                                    'Your connection has expired and needs to be re-authorized.',
                                    ['error_code' => $errorCode, 'status' => $response->status()]
                                )
                            );
                        }
                    } catch (Exception $e) {
                        Log::error('Failed to send IntegrationAuthenticationFailed notification', [
                            'group_id' => $group->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

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

        // Log the API request
        $this->logApiRequest('GET', $endpoint, [
            'Authorization' => '[REDACTED]',
        ]);

        $hub = SentrySdk::getCurrentHub();
        $parentSpan = $hub->getSpan();
        $span = $parentSpan?->startChild((new SpanContext)->setOp('http.client')->setDescription('GET ' . $this->baseUrl . $endpoint));
        $response = Http::withToken($token)
            ->get($this->baseUrl . $endpoint);
        $span?->finish();

        // Log the API response
        $this->logApiResponse('GET', $endpoint, $response->status(), $response->body(), $response->headers());

        if (! $response->successful()) {
            throw new Exception('API request failed: ' . $response->body());
        }

        return $response->json() ?? [];
    }

    abstract protected function getRequiredScopes(): string;

    abstract protected function fetchAccountInfoForGroup(IntegrationGroup $group): void;

    /**
     * Generate a PKCE code verifier
     */
    protected function generateCodeVerifier(): string
    {
        return Pkce::generateCodeVerifier();
    }

    /**
     * Generate a PKCE code challenge from a code verifier
     */
    protected function generateCodeChallenge(string $codeVerifier): string
    {
        return Pkce::generateCodeChallenge($codeVerifier);
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

    /**
     * Get the appropriate log channel for this plugin
     */
    protected function getLogChannel(): string
    {
        $pluginChannel = 'api_debug_' . str_replace([' ', '-', '_'], '_', static::getIdentifier());

        return config('logging.channels.' . $pluginChannel) ? $pluginChannel : 'api_debug';
    }

    /**
     * Log webhook payload for debugging
     */
    protected function logWebhookPayload(string $service, string $integrationId, array $payload, array $headers = []): void
    {
        log_integration_webhook(
            $service,
            $integrationId,
            $this->sanitizeData($payload),
            $this->sanitizeHeaders($headers),
            true // Use per-instance logging
        );
    }

    /**
     * Sanitize headers for logging (remove sensitive data)
     */
    protected function sanitizeHeaders(array $headers): array
    {
        $sensitiveHeaders = ['authorization', 'x-api-key', 'x-auth-token'];
        $sanitized = [];

        foreach ($headers as $key => $value) {
            $lowerKey = strtolower($key);
            if (in_array($lowerKey, $sensitiveHeaders)) {
                $sanitized[$key] = '[REDACTED]';
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize data for logging (remove sensitive data)
     */
    protected function sanitizeData(array $data): array
    {
        $sensitiveKeys = ['password', 'token', 'secret', 'key', 'auth'];
        $sanitized = [];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower($key);
            if (in_array($lowerKey, $sensitiveKeys)) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeData($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize response body for logging (limit size and remove sensitive data)
     */
    protected function sanitizeResponseBody(string $body): string
    {
        // Limit response body size to prevent huge logs
        $maxLength = 10000;
        if (strlen($body) > $maxLength) {
            return substr($body, 0, $maxLength) . '... [TRUNCATED]';
        }

        // Try to parse as JSON and sanitize sensitive fields
        $parsed = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
            $sanitized = $this->sanitizeData($parsed);

            return json_encode($sanitized, JSON_PRETTY_PRINT);
        }

        return $body;
    }
}
