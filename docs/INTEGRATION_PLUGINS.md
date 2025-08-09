# Integration Plugins Architecture

## Overview

The Spark integration plugin system provides a flexible architecture for connecting external services and converting their data into our standardized event/object/block format. The system supports both OAuth-based APIs and webhook-based integrations.

## Architecture Components

### 1. Base Plugin Interface

```php
<?php

namespace App\Integrations\Contracts;

use App\Models\Integration;
use App\Models\User;
use Illuminate\Http\Request;

interface IntegrationPlugin
{
    /**
     * Get the unique identifier for this integration
     */
    public static function getIdentifier(): string;
    
    /**
     * Get the display name for this integration
     */
    public static function getDisplayName(): string;
    
    /**
     * Get the description for this integration
     */
    public static function getDescription(): string;
    
    /**
     * Get the service type (oauth, webhook, etc.)
     */
    public static function getServiceType(): string;
    
    /**
     * Get the configuration schema for this integration
     */
    public static function getConfigurationSchema(): array;
    
    /**
     * Initialize the integration for a user
     */
    public function initialize(User $user): Integration;
    
    /**
     * Handle OAuth callback
     */
    public function handleOAuthCallback(Request $request, Integration $integration): void;
    
    /**
     * Handle webhook payload
     */
    public function handleWebhook(Request $request, Integration $integration): void;
    
    /**
     * Fetch data from external API
     */
    public function fetchData(Integration $integration): void;
    
    /**
     * Convert external data to our format
     */
    public function convertData(array $externalData, Integration $integration): array;
}
```

### 2. OAuth Plugin Base Class

```php
<?php

namespace App\Integrations\Base;

use App\Integrations\Contracts\IntegrationPlugin;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

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
        $state = encrypt([
            'integration_id' => $integration->id,
            'user_id' => $integration->user_id,
        ]);
        
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => $this->getRequiredScopes(),
            'state' => $state,
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
        
        // Exchange code for tokens
        $response = Http::post($this->baseUrl . '/oauth/token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri,
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
    
    abstract protected function getRequiredScopes(): string;
    abstract protected function fetchAccountInfo(Integration $integration): void;
}
```

### 3. Webhook Plugin Base Class

```php
<?php

namespace App\Integrations\Base;

use App\Integrations\Contracts\IntegrationPlugin;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

abstract class WebhookPlugin implements IntegrationPlugin
{
    public static function getServiceType(): string
    {
        return 'webhook';
    }
    
    public function initialize(User $user): Integration
    {
        $webhookSecret = Str::random(32);
        $webhookUrl = route('webhook.' . static::getIdentifier(), ['secret' => $webhookSecret]);
        
        $integration = Integration::create([
            'user_id' => $user->id,
            'service' => static::getIdentifier(),
            'name' => static::getDisplayName(),
            'account_id' => $webhookSecret,
            'access_token' => $webhookUrl,
            'refresh_token' => null,
            'expiry' => null,
            'refresh_expiry' => null,
        ]);
        
        return $integration;
    }
    
    public function handleWebhook(Request $request, Integration $integration): void
    {
        // Verify webhook signature if required
        if (!$this->verifyWebhookSignature($request, $integration)) {
            abort(401, 'Invalid webhook signature');
        }
        
        // Process the webhook payload
        $payload = $request->all();
        $convertedData = $this->convertData($payload, $integration);
        
        // Create events, objects, and blocks
        $this->createEventsFromWebhook($convertedData, $integration);
    }
    
    protected function verifyWebhookSignature(Request $request, Integration $integration): bool
    {
        // Override in child classes if signature verification is needed
        return true;
    }
    
    protected function createEventsFromWebhook(array $convertedData, Integration $integration): void
    {
        foreach ($convertedData['events'] ?? [] as $eventData) {
            // Create actor object
            $actor = $this->createOrUpdateObject($eventData['actor'], $integration);
            
            // Create target object
            $target = $this->createOrUpdateObject($eventData['target'], $integration);
            
            // Create event
            $event = $integration->user->events()->create([
                'source_id' => $eventData['source_id'],
                'time' => $eventData['time'],
                'integration_id' => $integration->id,
                'actor_id' => $actor->id,
                'actor_metadata' => $eventData['actor_metadata'] ?? [],
                'service' => $integration->service,
                'domain' => $eventData['domain'],
                'action' => $eventData['action'],
                'value' => $eventData['value'] ?? null,
                'value_multiplier' => $eventData['value_multiplier'] ?? 1,
                'value_unit' => $eventData['value_unit'] ?? null,
                'event_metadata' => $eventData['event_metadata'] ?? [],
                'target_id' => $target->id,
                'target_metadata' => $eventData['target_metadata'] ?? [],
                'embeddings' => $eventData['embeddings'] ?? null,
            ]);
            
            // Create blocks if any
            foreach ($eventData['blocks'] ?? [] as $blockData) {
                $event->blocks()->create([
                    'time' => $blockData['time'] ?? now(),
                    'integration_id' => $integration->id,
                    'title' => $blockData['title'],
                    'content' => $blockData['content'],
                    'url' => $blockData['url'] ?? null,
                    'media_url' => $blockData['media_url'] ?? null,
                    'value' => $blockData['value'] ?? null,
                    'value_multiplier' => $blockData['value_multiplier'] ?? 1,
                    'value_unit' => $blockData['value_unit'] ?? null,
                    'embeddings' => $blockData['embeddings'] ?? null,
                ]);
            }
        }
    }
    
    protected function createOrUpdateObject(array $objectData, Integration $integration): EventObject
    {
        return EventObject::updateOrCreate(
            [
                'integration_id' => $integration->id,
                'concept' => $objectData['concept'],
                'type' => $objectData['type'],
                'title' => $objectData['title'],
            ],
            [
                'time' => $objectData['time'] ?? now(),
                'content' => $objectData['content'] ?? null,
                'metadata' => $objectData['metadata'] ?? [],
                'url' => $objectData['url'] ?? null,
                'image_url' => $objectData['image_url'] ?? null,
                'embeddings' => $objectData['embeddings'] ?? null,
            ]
        );
    }
}
```

### 4. Plugin Registry

```php
<?php

namespace App\Integrations;

use App\Integrations\Contracts\IntegrationPlugin;
use Illuminate\Support\Collection;

class PluginRegistry
{
    private static array $plugins = [];
    
    public static function register(string $pluginClass): void
    {
        if (!is_subclass_of($pluginClass, IntegrationPlugin::class)) {
            throw new \InvalidArgumentException("Class must implement IntegrationPlugin");
        }
        
        $identifier = $pluginClass::getIdentifier();
        self::$plugins[$identifier] = $pluginClass;
    }
    
    public static function getPlugin(string $identifier): ?string
    {
        return self::$plugins[$identifier] ?? null;
    }
    
    public static function getAllPlugins(): Collection
    {
        return collect(self::$plugins);
    }
    
    public static function getOAuthPlugins(): Collection
    {
        return self::getAllPlugins()->filter(function ($pluginClass) {
            return $pluginClass::getServiceType() === 'oauth';
        });
    }
    
    public static function getWebhookPlugins(): Collection
    {
        return self::getAllPlugins()->filter(function ($pluginClass) {
            return $pluginClass::getServiceType() === 'webhook';
        });
    }
}
```

### 5. Example OAuth Plugin Implementation

```php
<?php

namespace App\Integrations\GitHub;

use App\Integrations\Base\OAuthPlugin;
use App\Models\Integration;
use Illuminate\Http\Request;

class GitHubPlugin extends OAuthPlugin
{
    protected string $baseUrl = 'https://api.github.com';
    protected string $clientId;
    protected string $clientSecret;
    protected string $redirectUri;
    
    public function __construct()
    {
        $this->clientId = config('services.github.client_id');
        $this->clientSecret = config('services.github.client_secret');
        $this->redirectUri = config('services.github.redirect') ?? route('integrations.oauth.callback', ['service' => 'github']);
    }
    
    public static function getIdentifier(): string
    {
        return 'github';
    }
    
    public static function getDisplayName(): string
    {
        return 'GitHub';
    }
    
    public static function getDescription(): string
    {
        return 'Connect your GitHub account to track repository activity';
    }
    
    public static function getConfigurationSchema(): array
    {
        return [
            'repositories' => [
                'type' => 'array',
                'label' => 'Repositories to track',
                'description' => 'Select which repositories to monitor',
                'required' => true,
            ],
            'events' => [
                'type' => 'array',
                'label' => 'Events to track',
                'options' => [
                    'push' => 'Push events',
                    'pull_request' => 'Pull request events',
                    'issue' => 'Issue events',
                    'commit_comment' => 'Commit comments',
                ],
                'required' => true,
            ],
        ];
    }
    
    protected function getRequiredScopes(): string
    {
        return 'repo read:user';
    }
    
    protected function fetchAccountInfo(Integration $integration): void
    {
        $userData = $this->makeAuthenticatedRequest('/user', $integration);
        
        $integration->update([
            'account_id' => $userData['login'],
        ]);
    }
    
    public function fetchData(Integration $integration): void
    {
        $config = $integration->configuration ?? [];
        $repositories = $config['repositories'] ?? [];
        $events = $config['events'] ?? ['push', 'pull_request'];
        
        foreach ($repositories as $repo) {
            $this->fetchRepositoryEvents($repo, $events, $integration);
        }
    }
    
    protected function fetchRepositoryEvents(string $repo, array $events, Integration $integration): void
    {
        foreach ($events as $eventType) {
            $endpoint = "/repos/{$repo}/events";
            $eventsData = $this->makeAuthenticatedRequest($endpoint, $integration);
            
            foreach ($eventsData as $eventData) {
                $convertedData = $this->convertData($eventData, $integration);
                $this->createEventFromData($convertedData, $integration);
            }
        }
    }
    
    public function convertData(array $externalData, Integration $integration): array
    {
        $eventType = $externalData['type'];
        
        switch ($eventType) {
            case 'PushEvent':
                return $this->convertPushEvent($externalData, $integration);
            case 'PullRequestEvent':
                return $this->convertPullRequestEvent($externalData, $integration);
            case 'IssuesEvent':
                return $this->convertIssueEvent($externalData, $integration);
            default:
                return [];
        }
    }
    
    protected function convertPushEvent(array $data, Integration $integration): array
    {
        $actor = [
            'concept' => 'user',
            'type' => 'github_user',
            'title' => $data['actor']['login'],
            'content' => $data['actor']['login'],
            'metadata' => [
                'github_id' => $data['actor']['id'],
                'avatar_url' => $data['actor']['avatar_url'],
            ],
            'url' => $data['actor']['html_url'],
            'image_url' => $data['actor']['avatar_url'],
        ];
        
        $target = [
            'concept' => 'repository',
            'type' => 'github_repo',
            'title' => $data['repo']['name'],
            'content' => $data['repo']['description'] ?? '',
            'metadata' => [
                'github_id' => $data['repo']['id'],
                'full_name' => $data['repo']['full_name'],
            ],
            'url' => $data['repo']['html_url'],
        ];
        
        $commits = $data['payload']['commits'] ?? [];
        $blocks = [];
        
        foreach ($commits as $commit) {
            $blocks[] = [
                'title' => 'Commit: ' . substr($commit['sha'], 0, 7),
                'content' => $commit['message'],
                'url' => $commit['url'],
                'value' => 1,
                'value_unit' => 'commit',
            ];
        }
        
        return [
            'events' => [[
                'source_id' => $data['id'],
                'time' => $data['created_at'],
                'actor' => $actor,
                'target' => $target,
                'domain' => 'repository',
                'action' => 'push',
                'value' => count($commits),
                'value_unit' => 'commits',
                'event_metadata' => [
                    'ref' => $data['payload']['ref'],
                    'before' => $data['payload']['before'],
                    'after' => $data['payload']['after'],
                ],
                'blocks' => $blocks,
            ]],
        ];
    }
    
    protected function convertPullRequestEvent(array $data, Integration $integration): array
    {
        $actor = [
            'concept' => 'user',
            'type' => 'github_user',
            'title' => $data['actor']['login'],
            'content' => $data['actor']['login'],
            'metadata' => [
                'github_id' => $data['actor']['id'],
                'avatar_url' => $data['actor']['avatar_url'],
            ],
            'url' => $data['actor']['html_url'],
            'image_url' => $data['actor']['avatar_url'],
        ];
        
        $pr = $data['payload']['pull_request'];
        $target = [
            'concept' => 'pull_request',
            'type' => 'github_pr',
            'title' => $pr['title'],
            'content' => $pr['body'] ?? '',
            'metadata' => [
                'github_id' => $pr['id'],
                'number' => $pr['number'],
                'state' => $pr['state'],
                'repository' => $data['repo']['full_name'],
            ],
            'url' => $pr['html_url'],
        ];
        
        return [
            'events' => [[
                'source_id' => $data['id'],
                'time' => $data['created_at'],
                'actor' => $actor,
                'target' => $target,
                'domain' => 'pull_request',
                'action' => $data['payload']['action'],
                'value' => 1,
                'value_unit' => 'pull_request',
                'event_metadata' => [
                    'repository' => $data['repo']['full_name'],
                    'number' => $pr['number'],
                ],
            ]],
        ];
    }
    
    protected function convertIssueEvent(array $data, Integration $integration): array
    {
        $actor = [
            'concept' => 'user',
            'type' => 'github_user',
            'title' => $data['actor']['login'],
            'content' => $data['actor']['login'],
            'metadata' => [
                'github_id' => $data['actor']['id'],
                'avatar_url' => $data['actor']['avatar_url'],
            ],
            'url' => $data['actor']['html_url'],
            'image_url' => $data['actor']['avatar_url'],
        ];
        
        $issue = $data['payload']['issue'];
        $target = [
            'concept' => 'issue',
            'type' => 'github_issue',
            'title' => $issue['title'],
            'content' => $issue['body'] ?? '',
            'metadata' => [
                'github_id' => $issue['id'],
                'number' => $issue['number'],
                'state' => $issue['state'],
                'repository' => $data['repo']['full_name'],
            ],
            'url' => $issue['html_url'],
        ];
        
        return [
            'events' => [[
                'source_id' => $data['id'],
                'time' => $data['created_at'],
                'actor' => $actor,
                'target' => $target,
                'domain' => 'issue',
                'action' => $data['payload']['action'],
                'value' => 1,
                'value_unit' => 'issue',
                'event_metadata' => [
                    'repository' => $data['repo']['full_name'],
                    'number' => $issue['number'],
                ],
            ]],
        ];
    }
}
```

### 6. Example Webhook Plugin Implementation

```php
<?php

namespace App\Integrations\Slack;

use App\Integrations\Base\WebhookPlugin;
use App\Models\Integration;
use Illuminate\Http\Request;

class SlackPlugin extends WebhookPlugin
{
    public static function getIdentifier(): string
    {
        return 'slack';
    }
    
    public static function getDisplayName(): string
    {
        return 'Slack';
    }
    
    public static function getDescription(): string
    {
        return 'Receive Slack events via webhook';
    }
    
    public static function getConfigurationSchema(): array
    {
        return [
            'events' => [
                'type' => 'array',
                'label' => 'Events to track',
                'options' => [
                    'message' => 'Message events',
                    'reaction_added' => 'Reaction events',
                    'file_shared' => 'File sharing events',
                ],
                'required' => true,
            ],
        ];
    }
    
    public function handleWebhook(Request $request, Integration $integration): void
    {
        $payload = $request->all();
        
        // Verify Slack signature
        if (!$this->verifySlackSignature($request, $integration)) {
            abort(401, 'Invalid Slack signature');
        }
        
        // Handle Slack URL verification
        if ($payload['type'] === 'url_verification') {
            return response()->json(['challenge' => $payload['challenge']]);
        }
        
        // Process the event
        $convertedData = $this->convertData($payload, $integration);
        $this->createEventsFromWebhook($convertedData, $integration);
    }
    
    protected function verifySlackSignature(Request $request, Integration $integration): bool
    {
        $signature = $request->header('X-Slack-Signature');
        $timestamp = $request->header('X-Slack-Request-Timestamp');
        $body = $request->getContent();
        
        $baseString = "v0:{$timestamp}:{$body}";
        $expectedSignature = 'v0=' . hash_hmac('sha256', $baseString, $integration->account_id);
        
        return hash_equals($expectedSignature, $signature);
    }
    
    public function convertData(array $externalData, Integration $integration): array
    {
        $event = $externalData['event'] ?? [];
        $eventType = $event['type'] ?? '';
        
        switch ($eventType) {
            case 'message':
                return $this->convertMessageEvent($externalData, $integration);
            case 'reaction_added':
                return $this->convertReactionEvent($externalData, $integration);
            case 'file_shared':
                return $this->convertFileEvent($externalData, $integration);
            default:
                return [];
        }
    }
    
    protected function convertMessageEvent(array $data, Integration $integration): array
    {
        $event = $data['event'];
        
        $actor = [
            'concept' => 'user',
            'type' => 'slack_user',
            'title' => $event['user'] ?? 'Unknown User',
            'content' => $event['user'] ?? 'Unknown User',
            'metadata' => [
                'slack_user_id' => $event['user'] ?? null,
                'channel' => $event['channel'] ?? null,
            ],
            'url' => null,
        ];
        
        $target = [
            'concept' => 'message',
            'type' => 'slack_message',
            'title' => 'Message in ' . ($event['channel'] ?? 'unknown channel'),
            'content' => $event['text'] ?? '',
            'metadata' => [
                'slack_message_id' => $event['ts'] ?? null,
                'channel' => $event['channel'] ?? null,
                'thread_ts' => $event['thread_ts'] ?? null,
            ],
            'url' => null,
        ];
        
        return [
            'events' => [[
                'source_id' => $data['event_id'],
                'time' => date('Y-m-d H:i:s', $event['ts'] ?? time()),
                'actor' => $actor,
                'target' => $target,
                'domain' => 'message',
                'action' => 'sent',
                'value' => 1,
                'value_unit' => 'message',
                'event_metadata' => [
                    'channel' => $event['channel'] ?? null,
                    'subtype' => $event['subtype'] ?? null,
                ],
            ]],
        ];
    }
    
    protected function convertReactionEvent(array $data, Integration $integration): array
    {
        $event = $data['event'];
        
        $actor = [
            'concept' => 'user',
            'type' => 'slack_user',
            'title' => $event['user'] ?? 'Unknown User',
            'content' => $event['user'] ?? 'Unknown User',
            'metadata' => [
                'slack_user_id' => $event['user'] ?? null,
            ],
            'url' => null,
        ];
        
        $target = [
            'concept' => 'reaction',
            'type' => 'slack_reaction',
            'title' => 'Reaction: ' . $event['reaction'],
            'content' => $event['reaction'],
            'metadata' => [
                'reaction' => $event['reaction'],
                'item_type' => $event['item']['type'] ?? null,
                'item_id' => $event['item']['ts'] ?? null,
            ],
            'url' => null,
        ];
        
        return [
            'events' => [[
                'source_id' => $data['event_id'],
                'time' => date('Y-m-d H:i:s', $event['event_ts'] ?? time()),
                'actor' => $actor,
                'target' => $target,
                'domain' => 'reaction',
                'action' => 'added',
                'value' => 1,
                'value_unit' => 'reaction',
                'event_metadata' => [
                    'reaction' => $event['reaction'],
                ],
            ]],
        ];
    }
    
    protected function convertFileEvent(array $data, Integration $integration): array
    {
        $event = $data['event'];
        $file = $event['file'] ?? [];
        
        $actor = [
            'concept' => 'user',
            'type' => 'slack_user',
            'title' => $file['user'] ?? 'Unknown User',
            'content' => $file['user'] ?? 'Unknown User',
            'metadata' => [
                'slack_user_id' => $file['user'] ?? null,
            ],
            'url' => null,
        ];
        
        $target = [
            'concept' => 'file',
            'type' => 'slack_file',
            'title' => $file['name'] ?? 'Unknown File',
            'content' => $file['title'] ?? '',
            'metadata' => [
                'slack_file_id' => $file['id'] ?? null,
                'file_type' => $file['filetype'] ?? null,
                'size' => $file['size'] ?? null,
            ],
            'url' => $file['url_private'] ?? null,
        ];
        
        return [
            'events' => [[
                'source_id' => $data['event_id'],
                'time' => date('Y-m-d H:i:s', $file['timestamp'] ?? time()),
                'actor' => $actor,
                'target' => $target,
                'domain' => 'file',
                'action' => 'shared',
                'value' => $file['size'] ?? 1,
                'value_unit' => 'bytes',
                'event_metadata' => [
                    'file_type' => $file['filetype'] ?? null,
                    'channels' => $file['channels'] ?? [],
                ],
            ]],
        ];
    }
}
```

### 7. Plugin Service Provider

```php
<?php

namespace App\Providers;

use App\Integrations\PluginRegistry;
use App\Integrations\GitHub\GitHubPlugin;
use App\Integrations\Slack\SlackPlugin;
use Illuminate\Support\ServiceProvider;

class IntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register plugins
        PluginRegistry::register(GitHubPlugin::class);
        PluginRegistry::register(SlackPlugin::class);
    }
    
    public function boot(): void
    {
        // Register routes
        $this->registerRoutes();
    }
    
    protected function registerRoutes(): void
    {
        // OAuth routes
        Route::middleware(['auth'])->group(function () {
            Route::get('/integrations/{service}/oauth', [IntegrationController::class, 'oauth'])
                ->name('integrations.oauth');
            Route::get('/integrations/{service}/callback', [IntegrationController::class, 'oauthCallback'])
                ->name('integrations.oauth.callback');
        });
        
        // Webhook routes
        Route::post('/webhook/{service}/{secret}', [WebhookController::class, 'handle'])
            ->name('webhook.handle');
    }
}
```

### 8. Integration Controller

```php
<?php

namespace App\Http\Controllers;

use App\Integrations\PluginRegistry;
use App\Models\Integration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class IntegrationController extends Controller
{
    public function index()
    {
        $plugins = PluginRegistry::getAllPlugins()->map(function ($pluginClass) {
            return [
                'identifier' => $pluginClass::getIdentifier(),
                'name' => $pluginClass::getDisplayName(),
                'description' => $pluginClass::getDescription(),
                'type' => $pluginClass::getServiceType(),
                'configuration_schema' => $pluginClass::getConfigurationSchema(),
            ];
        });
        
        $userIntegrations = Auth::user()->integrations()->with('user')->get();
        
        return view('integrations.index', compact('plugins', 'userIntegrations'));
    }
    
    public function oauth(string $service)
    {
        $pluginClass = PluginRegistry::getPlugin($service);
        if (!$pluginClass) {
            abort(404);
        }
        
        $plugin = new $pluginClass();
        $user = Auth::user();
        
        // Check if integration already exists
        $integration = $user->integrations()
            ->where('service', $service)
            ->first();
            
        if (!$integration) {
            $integration = $plugin->initialize($user);
        }
        
        $oauthUrl = $plugin->getOAuthUrl($integration);
        
        return redirect($oauthUrl);
    }
    
    public function oauthCallback(Request $request, string $service)
    {
        $pluginClass = PluginRegistry::getPlugin($service);
        if (!$pluginClass) {
            abort(404);
        }
        
        $plugin = new $pluginClass();
        $user = Auth::user();
        
        $integration = $user->integrations()
            ->where('service', $service)
            ->firstOrFail();
            
        $plugin->handleOAuthCallback($request, $integration);
        
        return redirect()->route('integrations.index')
            ->with('success', 'Integration connected successfully!');
    }
    
    public function configure(Request $request, Integration $integration)
    {
        $pluginClass = PluginRegistry::getPlugin($integration->service);
        if (!$pluginClass) {
            abort(404);
        }
        
        $schema = $pluginClass::getConfigurationSchema();
        
        $validated = $request->validate($this->buildValidationRules($schema));
        
        $integration->update([
            'configuration' => $validated,
        ]);
        
        return redirect()->route('integrations.index')
            ->with('success', 'Integration configured successfully!');
    }
    
    protected function buildValidationRules(array $schema): array
    {
        $rules = [];
        
        foreach ($schema as $field => $config) {
            $fieldRules = [];
            
            if ($config['required'] ?? false) {
                $fieldRules[] = 'required';
            } else {
                $fieldRules[] = 'nullable';
            }
            
            switch ($config['type']) {
                case 'array':
                    $fieldRules[] = 'array';
                    break;
                case 'string':
                    $fieldRules[] = 'string';
                    break;
                case 'integer':
                    $fieldRules[] = 'integer';
                    break;
            }
            
            $rules[$field] = $fieldRules;
        }
        
        return $rules;
    }
}
```

### 9. Webhook Controller

```php
<?php

namespace App\Http\Controllers;

use App\Integrations\PluginRegistry;
use App\Models\Integration;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function handle(Request $request, string $service, string $secret)
    {
        $pluginClass = PluginRegistry::getPlugin($service);
        if (!$pluginClass) {
            abort(404);
        }
        
        $integration = Integration::where('service', $service)
            ->where('account_id', $secret)
            ->first();
            
        if (!$integration) {
            abort(404);
        }
        
        $plugin = new $pluginClass();
        $plugin->handleWebhook($request, $integration);
        
        return response()->json(['status' => 'success']);
    }
}
```

### 10. Scheduled Jobs

```php
<?php

namespace App\Console\Commands;

use App\Integrations\PluginRegistry;
use App\Models\Integration;
use Illuminate\Console\Command;

class FetchIntegrationData extends Command
{
    protected $signature = 'integrations:fetch';
    protected $description = 'Fetch data from all OAuth integrations';
    
    public function handle(): int
    {
        $oauthIntegrations = Integration::whereHas('user')
            ->whereIn('service', PluginRegistry::getOAuthPlugins()->keys())
            ->get();
            
        foreach ($oauthIntegrations as $integration) {
            try {
                $pluginClass = PluginRegistry::getPlugin($integration->service);
                $plugin = new $pluginClass();
                
                $this->info("Fetching data for {$integration->service} integration {$integration->id}");
                $plugin->fetchData($integration);
                
            } catch (\Exception $e) {
                $this->error("Failed to fetch data for integration {$integration->id}: " . $e->getMessage());
            }
        }
        
        return 0;
    }
}
```

### 11. Database Migrations

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->json('configuration')->nullable()->after('refresh_expiry');
        });
    }
    
    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropColumn('configuration');
        });
    }
};
```

## Usage Examples

### Registering a Plugin

```php
// In your service provider
PluginRegistry::register(GitHubPlugin::class);
```

### Creating an Integration

```php
$user = Auth::user();
$plugin = new GitHubPlugin();
$integration = $plugin->initialize($user);
```

### Handling OAuth Callback

```php
$plugin = new GitHubPlugin();
$plugin->handleOAuthCallback($request, $integration);
```

### Processing Webhooks

```php
$plugin = new SlackPlugin();
$plugin->handleWebhook($request, $integration);
```

### Scheduling Data Fetching

```bash
# Add to your crontab
* * * * * php artisan integrations:fetch
```

## Benefits of This Architecture

1. **Modular**: Each integration is self-contained
2. **Extensible**: Easy to add new integrations
3. **Type-safe**: Strong typing with interfaces
4. **Testable**: Each component can be tested independently
5. **Flexible**: Supports both OAuth and webhook patterns
6. **Maintainable**: Clear separation of concerns
7. **Scalable**: Can handle multiple integration types

This architecture provides a solid foundation for building a comprehensive integration system that can grow with your needs. 