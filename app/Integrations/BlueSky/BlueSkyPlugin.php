<?php

namespace App\Integrations\BlueSky;

use App\Integrations\Base\OAuthPlugin;
use App\Jobs\OAuth\BlueSky\BlueSkyActivityInitialization;
use App\Jobs\OAuth\BlueSky\BlueSkyBookmarksPull;
use App\Jobs\OAuth\BlueSky\BlueSkyLikesPull;
use App\Jobs\OAuth\BlueSky\BlueSkyRepostsPull;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Laravel\Socialite\Facades\Socialite;
use Revolution\Bluesky\Facades\Bluesky;
use Throwable;

class BlueSkyPlugin extends OAuthPlugin
{
    protected string $baseUrl = 'https://bsky.social';

    public function __construct()
    {
        // BlueSky OAuth uses DPoP and doesn't require traditional client credentials
        // The package handles OAuth configuration via config/bluesky.php
        $privateKey = config('services.bluesky.oauth_private_key');

        if (app()->environment() !== 'testing' && empty($privateKey)) {
            throw new InvalidArgumentException('BlueSky OAuth private key is not configured. Run: php artisan bluesky:new-private-key');
        }
    }

    public static function getIdentifier(): string
    {
        return 'bluesky';
    }

    public static function getDisplayName(): string
    {
        return 'BlueSky';
    }

    public static function getDescription(): string
    {
        return 'Track your BlueSky bookmarks, likes, and reposts with rich post data extraction.';
    }

    public static function getConfigurationSchema(): array
    {
        return [
            'update_frequency_minutes' => [
                'type' => 'integer',
                'label' => 'Update Frequency (minutes)',
                'description' => 'How often to check for new activity (minimum 5 minutes)',
                'required' => true,
                'min' => 5,
                'default' => 15,
            ],
            'track_bookmarks' => [
                'type' => 'boolean',
                'label' => 'Track Bookmarks',
                'description' => 'Track posts you have bookmarked',
                'default' => true,
            ],
            'track_likes' => [
                'type' => 'boolean',
                'label' => 'Track Likes',
                'description' => 'Track posts you have liked',
                'default' => true,
            ],
            'track_reposts' => [
                'type' => 'boolean',
                'label' => 'Track Reposts',
                'description' => 'Track posts you have reposted',
                'default' => true,
            ],
            'include_quoted_posts' => [
                'type' => 'boolean',
                'label' => 'Include Quoted Posts',
                'description' => 'Extract and store quoted post content',
                'default' => true,
            ],
            'include_thread_context' => [
                'type' => 'boolean',
                'label' => 'Include Thread Context',
                'description' => 'Extract parent post information for replies',
                'default' => true,
            ],
        ];
    }

    public static function getInstanceTypes(): array
    {
        return [
            'activity' => [
                'label' => 'Activity Tracking',
                'schema' => self::getConfigurationSchema(),
            ],
        ];
    }

    public static function getIcon(): string
    {
        return 'fab.bluesky';
    }

    public static function getAccentColor(): string
    {
        return 'info';
    }

    public static function getDomain(): string
    {
        return 'online';
    }

    public static function supportsMigration(): bool
    {
        return true;
    }

    public static function getActionTypes(): array
    {
        return [
            'bookmarked_post' => [
                'icon' => 'fas.bookmark',
                'display_name' => 'Bookmarked Post',
                'description' => 'A post bookmarked on BlueSky',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
            'liked_post' => [
                'icon' => 'fas.heart',
                'display_name' => 'Liked Post',
                'description' => 'A post liked on BlueSky',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
            'reposted' => [
                'icon' => 'fas.retweet',
                'display_name' => 'Reposted',
                'description' => 'A post reposted on BlueSky',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
        ];
    }

    public static function getBlockTypes(): array
    {
        return [
            'post_content' => [
                'icon' => 'fas.file-lines',
                'display_name' => 'Post Content',
                'description' => 'Text content of the post',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
            'post_media' => [
                'icon' => 'fas.image',
                'display_name' => 'Post Media',
                'description' => 'Images or videos embedded in the post',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
            'quoted_post_content' => [
                'icon' => 'fas.quote-left',
                'display_name' => 'Quoted Post',
                'description' => 'Content of a quoted post',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
            'thread_parent' => [
                'icon' => 'fas.circle-up',
                'display_name' => 'Thread Parent',
                'description' => 'Parent post in thread',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
            'post_metrics' => [
                'icon' => 'fas.chart-simple',
                'display_name' => 'Post Metrics',
                'description' => 'Engagement metrics (likes, reposts, replies)',
                'display_with_object' => false,
                'value_unit' => null,
                'hidden' => false,
            ],
            'link_preview' => [
                'icon' => 'fas.link',
                'display_name' => 'Link Preview',
                'description' => 'URLs extracted from the post',
                'display_with_object' => false,
                'value_unit' => null,
                'hidden' => false,
            ],
        ];
    }

    public static function getObjectTypes(): array
    {
        return [
            'bluesky_user' => [
                'icon' => 'fas.user',
                'display_name' => 'BlueSky User',
                'description' => 'A BlueSky user account',
                'hidden' => false,
            ],
            'bluesky_post' => [
                'icon' => 'fas.file',
                'display_name' => 'BlueSky Post',
                'description' => 'A post on BlueSky',
                'hidden' => false,
            ],
        ];
    }

    public function getOAuthUrl(IntegrationGroup $group): string
    {
        // BlueSky uses Socialite provider from revolution/laravel-bluesky package
        // The OAuth flow is handled differently - we redirect to Socialite
        $state = encrypt([
            'group_id' => $group->id,
            'user_id' => $group->user_id,
        ]);

        return route('integrations.bluesky.redirect', ['state' => $state]);
    }

    public function handleOAuthCallback(Request $request, IntegrationGroup $group): void
    {
        // BlueSky OAuth is handled via Socialite
        // The revolution/laravel-bluesky package manages DPoP tokens and sessions
        try {
            $user = Socialite::driver('bluesky')
                ->stateless()
                ->user();

            // Store the OAuth session data
            $group->update([
                'account_id' => $user->getId(), // DID (Decentralized Identifier)
                'access_token' => $user->token, // Access token (managed by package)
                'refresh_token' => $user->refreshToken,
                'expiry' => $user->expiresIn ? now()->addSeconds($user->expiresIn) : null,
                'auth_metadata' => [
                    'did' => $user->getId(),
                    'handle' => $user->getNickname(),
                    'display_name' => $user->getName(),
                    'email' => $user->getEmail(),
                ],
            ]);

            Log::info('BlueSky OAuth successful', [
                'group_id' => $group->id,
                'did' => $user->getId(),
                'handle' => $user->getNickname(),
            ]);
        } catch (Throwable $e) {
            Log::error('BlueSky OAuth callback failed', [
                'error' => $e->getMessage(),
                'group_id' => $group->id,
            ]);
            throw new Exception('BlueSky OAuth failed: ' . $e->getMessage());
        }
    }

    public function scheduleJobs(Integration $integration): void
    {
        $config = $integration->configuration;

        // Schedule bookmark tracking
        if ($config['track_bookmarks'] ?? true) {
            BlueSkyBookmarksPull::dispatch($integration);
        }

        // Schedule likes tracking
        if ($config['track_likes'] ?? true) {
            BlueSkyLikesPull::dispatch($integration);
        }

        // Schedule reposts tracking
        if ($config['track_reposts'] ?? true) {
            BlueSkyRepostsPull::dispatch($integration);
        }
    }

    public function migrate(Integration $integration): void
    {
        BlueSkyActivityInitialization::dispatch($integration);
    }

    public function fetchData(Integration $integration): void
    {
        // Data fetching is handled by scheduled jobs (BlueSkyBookmarksPull, etc.)
        // This method is called by the scheduler to dispatch the appropriate jobs
        $this->scheduleJobs($integration);
    }

    public function convertData(array $externalData, Integration $integration): array
    {
        // OAuth plugins do not use convertData; return empty structure
        return [];
    }

    protected function getRequiredScopes(): string
    {
        // BlueSky OAuth requires the atproto scope (mandatory)
        return 'atproto';
    }

    protected function getConfigValue(Integration $integration, string $key, mixed $default = null): mixed
    {
        return $integration->configuration[$key] ?? $default;
    }

    protected function fetchAccountInfoForGroup(IntegrationGroup $group): void
    {
        // Account info is already set during OAuth callback
        // The DID and handle are stored in auth_metadata
        // No additional fetch needed for BlueSky
        if (! $group->account_id) {
            Log::warning('BlueSky: account_id not set for group', [
                'group_id' => $group->id,
            ]);
        }
    }
}
