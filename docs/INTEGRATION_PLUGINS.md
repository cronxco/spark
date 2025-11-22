# Integration Plugins

The integration plugin system provides a flexible architecture for connecting external services and converting their data into Spark's standardized event/object/block format.

## Overview

Spark uses a plugin-based architecture where each external service (Monzo, Oura, Spotify, GitHub, etc.) is implemented as a self-contained plugin. Plugins are registered via the `PluginRegistry` and managed through a group-first lifecycle where OAuth tokens and credentials are stored on `IntegrationGroup`, while individual configurations live on `Integration` instances.

## Architecture

### Plugin Types

Plugins are categorized by their service type, which determines how they authenticate and receive data:

| Type | Description | Data Flow |
|------|-------------|-----------|
| `oauth` | OAuth 2.0 authentication with external APIs | Polling via scheduled jobs |
| `webhook` | Receives data via HTTP webhooks | Push-based, real-time |
| `manual` | User-entered data, no external API | Direct user input |
| `apikey` | API key authentication | Polling via scheduled jobs |

### Base Classes

Base classes in `app/Integrations/Base/` provide common functionality:

| Class | Service Type | Key Features |
|-------|--------------|--------------|
| `OAuthPlugin` | `oauth` | PKCE flow, token refresh, authenticated requests |
| `WebhookPlugin` | `webhook` | Signature verification, webhook URL generation |
| `ManualPlugin` | `manual` | No external API, user data entry |

### Plugin Registry

The `PluginRegistry` (`app/Integrations/PluginRegistry.php`) maintains all available plugins:

```php
use App\Integrations\PluginRegistry;

// Get a plugin class by identifier
$pluginClass = PluginRegistry::getPlugin('spotify');

// Get a plugin instance
$plugin = PluginRegistry::getPluginInstance('spotify');

// Get all plugins of a specific type
$oauthPlugins = PluginRegistry::getOAuthPlugins();
$webhookPlugins = PluginRegistry::getWebhookPlugins();
$manualPlugins = PluginRegistry::getManualPlugins();
$apiKeyPlugins = PluginRegistry::getApiKeyPlugins();

// Get all plugins with their configuration metadata
$pluginsWithConfig = PluginRegistry::getPluginsWithConfig();
```

### Data Model Hierarchy

```
IntegrationGroup (stores OAuth tokens/credentials)
  |-> Integration (specific instance with configuration)
      |-> EventObject (entities: bank account, playlist, device)
          |-> Event (timestamped data points)
              |-> Block (data visualizations)
```

## Implementation

### IntegrationPlugin Contract

All plugins must implement the `IntegrationPlugin` contract:

```php
interface IntegrationPlugin
{
    // Identity
    public static function getIdentifier(): string;
    public static function getDisplayName(): string;
    public static function getDescription(): string;
    public static function getServiceType(): string;

    // Display
    public static function getIcon(): string;
    public static function getAccentColor(): string;
    public static function getDomain(): string;

    // Configuration
    public static function getConfigurationSchema(): array;
    public static function getInstanceTypes(): array;
    public static function getActionTypes(): array;
    public static function getBlockTypes(): array;
    public static function getObjectTypes(): array;

    // Lifecycle
    public static function supportsMigration(): bool;
    public static function getTimeUntilStaleMinutes(): ?int;

    // Group-first lifecycle
    public function initializeGroup(User $user): IntegrationGroup;
    public function createInstance(
        IntegrationGroup $group,
        string $instanceType,
        array $initialConfig = [],
        bool $withMigration = false
    ): Integration;

    // Data handling
    public function handleOAuthCallback(Request $request, IntegrationGroup $group): void;
    public function handleWebhook(Request $request, Integration $integration): void;
    public function fetchData(Integration $integration): void;
    public function convertData(array $externalData, Integration $integration): array;
}
```

### Creating an OAuth Plugin

```php
namespace App\Integrations\Example;

use App\Integrations\Base\OAuthPlugin;
use App\Models\Integration;
use App\Models\IntegrationGroup;

class ExamplePlugin extends OAuthPlugin
{
    protected string $baseUrl = 'https://api.example.com';
    protected string $clientId;
    protected string $clientSecret;
    protected string $redirectUri;

    public function __construct()
    {
        $this->clientId = config('services.example.client_id');
        $this->clientSecret = config('services.example.client_secret');
        $this->redirectUri = route('integrations.oauth.callback', ['service' => 'example']);
    }

    public static function getIdentifier(): string
    {
        return 'example';
    }

    public static function getDisplayName(): string
    {
        return 'Example Service';
    }

    public static function getDescription(): string
    {
        return 'Connect your Example account to track activity.';
    }

    public static function getIcon(): string
    {
        return 'o-cube';
    }

    public static function getAccentColor(): string
    {
        return 'primary';
    }

    public static function getDomain(): string
    {
        return 'online'; // health, money, media, knowledge, online
    }

    protected function getRequiredScopes(): string
    {
        return 'read write';
    }

    protected function fetchAccountInfoForGroup(IntegrationGroup $group): void
    {
        // Fetch and store account information after OAuth
        $temp = new Integration();
        $temp->setRelation('group', $group);
        $userData = $this->makeAuthenticatedRequest('/user', $temp);
        $group->update(['account_id' => $userData['id']]);
    }

    public function fetchData(Integration $integration): void
    {
        // Implement data fetching logic
    }

    public function convertData(array $externalData, Integration $integration): array
    {
        // Convert external data to Spark format
        return [];
    }
}
```

### Creating a Webhook Plugin

```php
namespace App\Integrations\Example;

use App\Integrations\Base\WebhookPlugin;
use App\Models\Integration;

class ExampleWebhookPlugin extends WebhookPlugin
{
    public static function getIdentifier(): string
    {
        return 'example-webhook';
    }

    public static function getDisplayName(): string
    {
        return 'Example Webhook';
    }

    public static function getDescription(): string
    {
        return 'Receive events via webhook.';
    }

    public static function getIcon(): string
    {
        return 'o-bolt';
    }

    public static function getAccentColor(): string
    {
        return 'warning';
    }

    public static function getDomain(): string
    {
        return 'online';
    }

    public function convertData(array $externalData, Integration $integration): array
    {
        return [
            'events' => [[
                'source_id' => $externalData['id'],
                'time' => $externalData['timestamp'],
                'actor' => [
                    'concept' => 'user',
                    'type' => 'example_user',
                    'title' => $externalData['user']['name'],
                ],
                'target' => [
                    'concept' => 'item',
                    'type' => 'example_item',
                    'title' => $externalData['item']['name'],
                ],
                'domain' => 'online',
                'action' => 'created',
                'value' => 1,
                'value_unit' => 'item',
            ]],
        ];
    }
}
```

### Registering Plugins

Register plugins in `IntegrationServiceProvider`:

```php
namespace App\Providers;

use App\Integrations\PluginRegistry;
use App\Integrations\Example\ExamplePlugin;
use Illuminate\Support\ServiceProvider;

class IntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        PluginRegistry::register(ExamplePlugin::class);
    }
}
```

## Configuration

### Configuration Schema

The configuration schema defines user-configurable options:

```php
public static function getConfigurationSchema(): array
{
    return [
        'update_frequency_minutes' => [
            'type' => 'integer',
            'label' => 'Update Frequency (minutes)',
            'description' => 'How often to check for new data',
            'required' => true,
            'min' => 5,
            'default' => 15,
        ],
        'auto_tag' => [
            'type' => 'boolean',
            'label' => 'Auto-tag Events',
            'description' => 'Automatically tag events',
            'default' => false,
        ],
        'categories' => [
            'type' => 'array',
            'label' => 'Categories to Track',
            'options' => [
                'category_a' => 'Category A',
                'category_b' => 'Category B',
            ],
            'required' => true,
        ],
    ];
}
```

### Instance Types

Instance types allow multiple configurations per integration group:

```php
public static function getInstanceTypes(): array
{
    return [
        'activity' => [
            'label' => 'Activity Tracking',
            'schema' => self::getConfigurationSchema(),
        ],
        'metrics' => [
            'label' => 'Metrics Dashboard',
            'schema' => [
                'metrics_to_track' => [
                    'type' => 'array',
                    'label' => 'Metrics',
                    'required' => true,
                ],
            ],
        ],
    ];
}
```

### Action Types

Action types define event actions with display settings:

```php
public static function getActionTypes(): array
{
    return [
        'listened_to' => [
            'icon' => 'o-play',
            'display_name' => 'Listened to Track',
            'description' => 'A track that was listened to',
            'display_with_object' => true,
            'value_unit' => null,
            'hidden' => false,
        ],
    ];
}
```

### Block Types

Block types define data visualization blocks:

```php
public static function getBlockTypes(): array
{
    return [
        'album_art' => [
            'icon' => 'o-photo',
            'display_name' => 'Album Artwork',
            'description' => 'Album cover artwork',
            'display_with_object' => true,
            'value_unit' => null,
            'hidden' => false,
        ],
    ];
}
```

### Object Types

Object types define entities the integration manages:

```php
public static function getObjectTypes(): array
{
    return [
        'spotify_track' => [
            'icon' => 'o-musical-note',
            'display_name' => 'Spotify Track',
            'description' => 'A Spotify track',
            'hidden' => false,
        ],
    ];
}
```

### Valid Domains

Plugins must use one of these domains:

- `health` - Health and fitness data
- `money` - Financial data
- `media` - Media consumption
- `knowledge` - Knowledge and notes
- `online` - Online activity

### Spotlight Commands

Plugins can provide Spotlight commands by implementing `SupportsSpotlightCommands`:

```php
use App\Integrations\Contracts\SupportsSpotlightCommands;

class ExamplePlugin extends OAuthPlugin implements SupportsSpotlightCommands
{
    public static function getSpotlightCommands(): array
    {
        return [
            'sync-now' => [
                'title' => 'Sync Example Now',
                'subtitle' => 'Trigger an immediate sync',
                'icon' => 'o-arrow-path',
                'action' => 'dispatch_event',
                'actionParams' => ['name' => 'sync-example', 'close' => true],
                'priority' => 5,
            ],
        ];
    }
}
```

## Related Documentation

- `CLAUDE.md` - Main development guide with plugin system overview
- `app/Integrations/Contracts/IntegrationPlugin.php` - Full contract definition
- `app/Integrations/Base/` - Base class implementations
- `app/Providers/IntegrationServiceProvider.php` - Plugin registration
