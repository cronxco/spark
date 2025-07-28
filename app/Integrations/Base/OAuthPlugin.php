<?php

namespace App\Integrations\Base;

use App\Integrations\Contracts\IntegrationPlugin;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

abstract class OAuthPlugin implements IntegrationPlugin
{
    protected string $baseUrl;
    protected string $clientId;
    protected string $clientSecret;
    protected string $redirectUri;
    
    public static function getServiceType(): string
    {
        return 'oauth';
    }
    
    public function initialize(User $user): Integration
    {
        $integration = Integration::create([
            'user_id' => $user->id,
            'service' => static::getIdentifier(),
            'name' => static::getDisplayName(),
            'account_id' => null,
            'access_token' => null,
            'refresh_token' => null,
            'expiry' => null,
            'refresh_expiry' => null,
        ]);
        
        return $integration;
    }
    
    public function getOAuthUrl(Integration $integration): string
    {
        // Generate PKCE code verifier and challenge
        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->generateCodeChallenge($codeVerifier);
        
        // Generate CSRF token
        $csrfToken = Str::random(32);
        
        // Store CSRF token in session for validation
        $sessionKey = 'oauth_csrf_' . session_id() . '_' . $integration->id;
        Session::put($sessionKey, $csrfToken);
        
        $state = encrypt([
            'integration_id' => $integration->id,
            'user_id' => $integration->user_id,
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
    
    public function handleOAuthCallback(Request $request, Integration $integration): void
    {
        $code = $request->get('code');
        $state = $request->get('state');
        
        // Verify state
        $stateData = decrypt($state);
        if ($stateData['integration_id'] !== $integration->id) {
            throw new \Exception('Invalid state parameter');
        }
        
        // Validate CSRF token
        if (!isset($stateData['csrf_token']) || !$this->validateCsrfToken($stateData['csrf_token'], $integration)) {
            throw new \Exception('Invalid CSRF token');
        }
        
        // Get code verifier from state
        $codeVerifier = $stateData['code_verifier'] ?? null;
        if (!$codeVerifier) {
            throw new \Exception('Missing code verifier');
        }
        
        // Exchange code for tokens with PKCE
        $response = Http::post($this->baseUrl . '/oauth/token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri,
            'code_verifier' => $codeVerifier,
        ]);
        
        if (!$response->successful()) {
            throw new \Exception('Failed to exchange code for tokens');
        }
        
        $tokenData = $response->json();
        
        // Update integration with tokens
        $integration->update([
            'access_token' => $tokenData['access_token'],
            'refresh_token' => $tokenData['refresh_token'] ?? null,
            'expiry' => isset($tokenData['expires_in']) 
                ? now()->addSeconds($tokenData['expires_in']) 
                : null,
        ]);
        
        // Fetch account information
        $this->fetchAccountInfo($integration);
    }
    
    protected function refreshToken(Integration $integration): void
    {
        $response = Http::post($this->baseUrl . '/oauth/token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $integration->refresh_token,
            'grant_type' => 'refresh_token',
        ]);
        
        if (!$response->successful()) {
            throw new \Exception('Failed to refresh token');
        }
        
        $tokenData = $response->json();
        
        $integration->update([
            'access_token' => $tokenData['access_token'],
            'refresh_token' => $tokenData['refresh_token'] ?? $integration->refresh_token,
            'expiry' => isset($tokenData['expires_in']) 
                ? now()->addSeconds($tokenData['expires_in']) 
                : null,
        ]);
    }
    
    protected function makeAuthenticatedRequest(string $endpoint, Integration $integration): array
    {
        // Check if token needs refresh
        if ($integration->expiry && $integration->expiry->isPast()) {
            $this->refreshToken($integration);
        }
        
        $response = Http::withToken($integration->access_token)
            ->get($this->baseUrl . $endpoint);
            
        if (!$response->successful()) {
            throw new \Exception('API request failed: ' . $response->body());
        }
        
        return $response->json();
    }
    
    public function handleWebhook(Request $request, Integration $integration): void
    {
        // OAuth plugins don't handle webhooks
        throw new \Exception('OAuth plugins do not handle webhooks');
    }
    
    abstract protected function getRequiredScopes(): string;
    abstract protected function fetchAccountInfo(Integration $integration): void;
    
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
    protected function validateCsrfToken(string $token, Integration $integration): bool
    {
        // Get the session key for this integration
        $sessionKey = 'oauth_csrf_' . session_id() . '_' . $integration->id;
        
        // Retrieve stored token from session
        $storedToken = Session::get($sessionKey);
        
        if (!$storedToken) {
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