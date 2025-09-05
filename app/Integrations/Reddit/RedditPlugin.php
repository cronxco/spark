<?php

namespace App\Integrations\Reddit;

use App\Integrations\Base\OAuthPlugin;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;

class RedditPlugin extends OAuthPlugin
{
    protected string $baseUrl = 'https://oauth.reddit.com';

    protected string $authBase = 'https://www.reddit.com/api/v1';

    protected string $clientId;

    protected string $clientSecret;

    protected string $redirectUri;

    public function __construct()
    {
        $this->clientId = config('services.reddit.client_id') ?? '';
        $this->clientSecret = config('services.reddit.client_secret') ?? '';
        $this->redirectUri = config('services.reddit.redirect') ?? route('integrations.oauth.callback', ['service' => 'reddit']);

        if (app()->environment() !== 'testing' && (empty($this->clientId) || empty($this->clientSecret))) {
            throw new InvalidArgumentException('Reddit OAuth credentials are not configured');
        }
    }

    public static function getIdentifier(): string
    {
        return 'reddit';
    }

    public static function getDisplayName(): string
    {
        return 'Reddit';
    }

    public static function getDescription(): string
    {
        return 'Connect your Reddit account to import saved posts and comments as events with rich blocks (images and links).';
    }

    public static function getConfigurationSchema(): array
    {
        return [
            'update_frequency_minutes' => [
                'type' => 'integer',
                'label' => 'Update Frequency (minutes)',
                'description' => 'How often to fetch new saved items (default 15).',
                'required' => true,
                'min' => 1,
                'default' => 15,
            ],
        ];
    }

    public static function getInstanceTypes(): array
    {
        return [
            'saved' => [
                'label' => 'Saved Items',
                'schema' => self::getConfigurationSchema(),
            ],
        ];
    }

    public static function getIcon(): string
    {
        return 'o-bookmark';
    }

    public static function getAccentColor(): string
    {
        return 'warning';
    }

    public static function getDomain(): string
    {
        return 'online';
    }

    public static function getActionTypes(): array
    {
        return [
            'bookmarked' => [
                'icon' => 'o-bookmark',
                'display_name' => 'Bookmarked',
                'description' => 'A saved post or comment on Reddit',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
        ];
    }

    public static function getBlockTypes(): array
    {
        return [
            'image' => [
                'icon' => 'o-photo',
                'display_name' => 'Image',
                'description' => 'Image contained in the post',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
            'url' => [
                'icon' => 'o-link',
                'display_name' => 'URL',
                'description' => 'Link referenced in the content',
                'display_with_object' => false,
                'value_unit' => null,
                'hidden' => false,
            ],
        ];
    }

    public static function getObjectTypes(): array
    {
        return [
            'reddit_account' => [
                'icon' => 'o-user',
                'display_name' => 'Reddit Account',
                'description' => 'A Reddit user account',
                'hidden' => false,
            ],
            'reddit_post' => [
                'icon' => 'o-document',
                'display_name' => 'Reddit Post',
                'description' => 'A post on Reddit',
                'hidden' => false,
            ],
            'reddit_comment' => [
                'icon' => 'o-chat-bubble-left-right',
                'display_name' => 'Reddit Comment',
                'description' => 'A comment on Reddit',
                'hidden' => false,
            ],
            'reddit_image' => [
                'icon' => 'o-photo',
                'display_name' => 'Reddit Image',
                'description' => 'An image contained in a Reddit post',
                'hidden' => true,
            ],
        ];
    }

    public function getOAuthUrl(IntegrationGroup $group): string
    {
        // Generate PKCE code verifier and challenge
        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->generateCodeChallenge($codeVerifier);

        // CSRF token
        $csrfToken = Str::random(32);
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
            'response_type' => 'code',
            'state' => $state,
            'redirect_uri' => $this->redirectUri,
            'duration' => 'permanent',
            'scope' => $this->getRequiredScopes(),
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];

        return $this->authBase . '/authorize?' . http_build_query($params);
    }

    public function handleOAuthCallback(Request $request, IntegrationGroup $group): void
    {
        $code = $request->get('code');
        $state = $request->get('state');

        if (! $code || ! $state) {
            throw new Exception('Invalid OAuth callback: missing parameters');
        }

        // Verify state
        $stateData = decrypt($state);
        if ((string) ($stateData['group_id'] ?? '') !== (string) $group->id) {
            throw new Exception('Invalid state parameter');
        }

        // Validate CSRF token
        if (! isset($stateData['csrf_token']) || ! $this->validateCsrfToken($stateData['csrf_token'], $group)) {
            throw new Exception('Invalid CSRF token');
        }

        $codeVerifier = $stateData['code_verifier'] ?? null;
        if (! $codeVerifier) {
            throw new Exception('Missing code verifier');
        }

        // Log request
        $this->logApiRequest('POST', '/access_token', [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'User-Agent' => config('services.reddit.useragent') ?? 'SparkApp/1.0',
        ], [
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri,
        ]);

        // Exchange code for tokens
        $hub = SentrySdk::getCurrentHub();
        $parentSpan = $hub->getSpan();
        $span = $parentSpan?->startChild((new SpanContext)->setOp('http.client')->setDescription('POST ' . $this->authBase . '/access_token'));
        $response = Http::asForm()
            ->withHeaders([
                'User-Agent' => config('services.reddit.useragent') ?? 'SparkApp/1.0',
            ])
            ->withBasicAuth($this->clientId, $this->clientSecret)
            ->post($this->authBase . '/access_token', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->redirectUri,
                'code_verifier' => $codeVerifier,
            ]);
        $span?->finish();

        $this->logApiResponse('POST', '/access_token', $response->status(), $response->body(), $response->headers());

        if (! $response->successful()) {
            Log::error('Reddit token exchange failed', [
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            throw new Exception('Failed to exchange code for tokens');
        }

        $tokenData = $response->json();

        $group->update([
            'access_token' => $tokenData['access_token'] ?? null,
            'refresh_token' => $tokenData['refresh_token'] ?? null,
            'expiry' => isset($tokenData['expires_in']) ? now()->addSeconds($tokenData['expires_in']) : null,
        ]);

        // Fetch account info (sets account_id to the Reddit username)
        $this->fetchAccountInfoForGroup($group);
    }

    /**
     * Public helper to allow jobs to call authenticated API.
     */
    public function makeAuthenticatedApiRequest(string $endpoint, Integration $integration): array
    {
        return $this->makeAuthenticatedRequest($endpoint, $integration);
    }

    /**
     * Implement interface - primary fetching is handled by jobs; this is a no-op.
     */
    public function fetchData(Integration $integration): void
    {
        // Intentionally left minimal; scheduler uses RedditSavedPull job.
        Log::info('RedditPlugin::fetchData invoked - prefer using RedditSavedPull job', [
            'integration_id' => $integration->id,
        ]);
    }

    /**
     * OAuth plugins do not use convertData; return empty structure.
     */
    public function convertData(array $externalData, Integration $integration): array
    {
        return [];
    }

    protected function refreshToken(IntegrationGroup $group): void
    {
        if (empty($group->refresh_token)) {
            Log::error('Reddit token refresh skipped: missing refresh_token', [
                'group_id' => $group->id,
            ]);

            return;
        }

        $this->logApiRequest('POST', '/access_token', [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'User-Agent' => config('services.reddit.useragent') ?? 'SparkApp/1.0',
        ], [
            'grant_type' => 'refresh_token',
        ]);

        $hub = SentrySdk::getCurrentHub();
        $parentSpan = $hub->getSpan();
        $span = $parentSpan?->startChild((new SpanContext)->setOp('http.client')->setDescription('POST ' . $this->authBase . '/access_token'));
        $response = Http::asForm()
            ->withHeaders([
                'User-Agent' => config('services.reddit.useragent') ?? 'SparkApp/1.0',
            ])
            ->withBasicAuth($this->clientId, $this->clientSecret)
            ->post($this->authBase . '/access_token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $group->refresh_token,
            ]);
        $span?->finish();

        $this->logApiResponse('POST', '/access_token', $response->status(), $response->body(), $response->headers());

        if (! $response->successful()) {
            throw new Exception('Failed to refresh token');
        }

        $tokenData = $response->json();

        $group->update([
            'access_token' => $tokenData['access_token'] ?? $group->access_token,
            'refresh_token' => $tokenData['refresh_token'] ?? $group->refresh_token,
            'expiry' => isset($tokenData['expires_in']) ? now()->addSeconds($tokenData['expires_in']) : null,
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

        $this->logApiRequest('GET', $endpoint, [
            'Authorization' => '[REDACTED]',
            'User-Agent' => config('services.reddit.useragent') ?? 'SparkApp/1.0',
        ]);

        $hub = SentrySdk::getCurrentHub();
        $parentSpan = $hub->getSpan();
        $span = $parentSpan?->startChild((new SpanContext)->setOp('http.client')->setDescription('GET ' . $this->baseUrl . $endpoint));
        $response = Http::withToken($token)
            ->withHeaders([
                'User-Agent' => config('services.reddit.useragent') ?? 'SparkApp/1.0',
            ])
            ->get($this->baseUrl . $endpoint);
        $span?->finish();

        $this->logApiResponse('GET', $endpoint, $response->status(), $response->body(), $response->headers());

        if (! $response->successful()) {
            throw new Exception('API request failed: ' . $response->body());
        }

        return $response->json() ?? [];
    }

    protected function getRequiredScopes(): string
    {
        // identity to resolve user; history to read saved items
        return 'identity history read save';
    }

    protected function fetchAccountInfoForGroup(IntegrationGroup $group): void
    {
        $temp = new Integration;
        $temp->setRelation('group', $group);
        $me = $this->makeAuthenticatedRequest('/api/v1/me', $temp);

        $group->update([
            'account_id' => $me['name'] ?? null,
        ]);
    }
}
