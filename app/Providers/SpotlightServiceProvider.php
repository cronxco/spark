<?php

namespace App\Providers;

use App\Spotlight\Actions\ClearBinAction;
use App\Spotlight\Queries\Actions\BookmarkUrlQuery;
use App\Spotlight\Queries\Actions\ContextualActionsQuery;
use App\Spotlight\Queries\Actions\GlobalActionsQuery;
use App\Spotlight\Queries\Integration\IntegrationSearchQuery;
use App\Spotlight\Queries\Integration\PluginCommandsQuery;
use App\Spotlight\Queries\Navigation\AdminNavigationQuery;
use App\Spotlight\Queries\Navigation\CoreNavigationQuery;
use App\Spotlight\Queries\Navigation\DailyNavigationQuery;
use App\Spotlight\Queries\Navigation\FetchNavigationQuery;
use App\Spotlight\Queries\Navigation\HelpQuery;
use App\Spotlight\Queries\Navigation\SettingsNavigationQuery;
use App\Spotlight\Queries\Scoped\AccountActionsQuery;
use App\Spotlight\Queries\Scoped\AccountEventsQuery;
use App\Spotlight\Queries\Scoped\AccountIntegrationQuery;
use App\Spotlight\Queries\Scoped\BlockActionsQuery;
use App\Spotlight\Queries\Scoped\BlockEventQuery;
use App\Spotlight\Queries\Scoped\BlockRelatedBlocksQuery;
use App\Spotlight\Queries\Scoped\EventActionsQuery;
use App\Spotlight\Queries\Scoped\EventActorQuery;
use App\Spotlight\Queries\Scoped\EventBlocksQuery;
use App\Spotlight\Queries\Scoped\EventIntegrationQuery;
use App\Spotlight\Queries\Scoped\EventTargetQuery;
use App\Spotlight\Queries\Scoped\IntegrationActionsQuery;
use App\Spotlight\Queries\Scoped\IntegrationBlocksQuery;
use App\Spotlight\Queries\Scoped\IntegrationObjectsQuery;
use App\Spotlight\Queries\Scoped\MetricActionsQuery;
use App\Spotlight\Queries\Scoped\MetricAnomaliesQuery;
use App\Spotlight\Queries\Scoped\MetricEventsQuery;
use App\Spotlight\Queries\Scoped\ObjectActionsQuery;
use App\Spotlight\Queries\Scoped\ObjectEventsQuery;
use App\Spotlight\Queries\Scoped\ObjectIntegrationQuery;
use App\Spotlight\Queries\Search\BlockSearchQuery;
use App\Spotlight\Queries\Search\EventSearchQuery;
use App\Spotlight\Queries\Search\FinancialAccountSearchQuery;
use App\Spotlight\Queries\Search\IntegrationEventsQuery;
use App\Spotlight\Queries\Search\MetricSearchQuery;
use App\Spotlight\Queries\Search\MetricTrendsQuery;
use App\Spotlight\Queries\Search\ObjectSearchQuery;
use App\Spotlight\Queries\Search\SemanticSearchQuery;
use App\Spotlight\Queries\Search\TagSearchQuery;
use App\Spotlight\Scopes\BlockDetailScope;
use App\Spotlight\Scopes\EventDetailScope;
use App\Spotlight\Scopes\FinancialAccountScope;
use App\Spotlight\Scopes\IntegrationDetailScope;
use App\Spotlight\Scopes\MetricDetailScope;
use App\Spotlight\Scopes\ObjectDetailScope;
use Illuminate\Support\ServiceProvider;
use WireElements\Pro\Components\Spotlight\Spotlight;
use WireElements\Pro\Components\Spotlight\SpotlightMode;
use WireElements\Pro\Components\Spotlight\SpotlightScopeToken;

class SpotlightServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Spotlight::setup(function () {
            // Register custom actions
            $this->registerActions();

            // Register all modes
            $this->registerModes();

            // Register all groups
            $this->registerGroups();

            // Register all tokens
            $this->registerTokens();

            // Register all scopes
            $this->registerScopes();

            // Register all queries
            $this->registerQueries();

            // Register all tips
            $this->registerTips();
        });
    }

    /**
     * Register custom Spotlight actions.
     */
    protected function registerActions(): void
    {
        Spotlight::registerAction('clear_bin', ClearBinAction::class);
    }

    /**
     * Register Spotlight search modes.
     */
    protected function registerModes(): void
    {
        Spotlight::registerModes(
            SpotlightMode::make('actions', 'Actions & Commands')->setCharacter('>'),
            SpotlightMode::make('tags', 'Search Tags')->setCharacter('#'),
            SpotlightMode::make('metrics', 'Search Metrics')->setCharacter('$'),
            SpotlightMode::make('integrations', 'Search Integrations')->setCharacter('@'),
            SpotlightMode::make('admin', 'Admin Commands')->setCharacter('!'),
            SpotlightMode::make('help', 'Help & Tips')->setCharacter('?'),
        );
    }

    /**
     * Register Spotlight result groups.
     */
    protected function registerGroups(): void
    {
        Spotlight::registerGroup('commands', 'Commands', 1);
        Spotlight::registerGroup('events', 'Events', 2);
        Spotlight::registerGroup('objects', 'Objects', 3);
        Spotlight::registerGroup('accounts', 'Financial Accounts', 4);
        Spotlight::registerGroup('blocks', 'Blocks', 5);
        Spotlight::registerGroup('navigation', 'Navigation', 6);
        Spotlight::registerGroup('tags', 'Tags', 7);
        Spotlight::registerGroup('metrics', 'Metrics', 8);
        Spotlight::registerGroup('integrations', 'Integrations', 9);
        Spotlight::registerGroup('admin', 'Admin', 10);
    }

    /**
     * Register Spotlight scope tokens.
     */
    protected function registerTokens(): void
    {
        Spotlight::registerTokens(
            SpotlightScopeToken::make('integration', function ($token, $integration = null) {
                // Handle both model objects (from setTokens) and arrays (from scopes)
                if (is_array($integration)) {
                    // From scope - parameters already provided
                    $token->setParameters($integration);
                    $token->setText($integration['name'] ?? 'Integration');
                } elseif ($integration) {
                    // From setTokens - extract from model
                    $token->setParameters([
                        'id' => $integration->id,
                        'name' => $integration->name,
                    ]);
                    $token->setText($integration->name);
                } else {
                    // No data - use existing parameters
                    $token->setText($token->getParameter('name') ?? 'Integration');
                }
            }),
            SpotlightScopeToken::make('metric', function ($token, $metric = null) {
                // Handle both model objects (from setTokens) and arrays (from scopes)
                if (is_array($metric)) {
                    // From scope - parameters already provided
                    $token->setParameters($metric);
                    $token->setText($metric['name'] ?? 'Metric');
                } elseif ($metric) {
                    // From setTokens - extract from model
                    $token->setParameters([
                        'id' => $metric->id,
                        'name' => $metric->getDisplayName(),
                    ]);
                    $token->setText($metric->getDisplayName());
                } else {
                    // No data - use existing parameters
                    $token->setText($token->getParameter('name') ?? 'Metric');
                }
            }),
            SpotlightScopeToken::make('account', function ($token, $account = null) {
                // Handle both model objects (from setTokens) and arrays (from scopes)
                if (is_array($account)) {
                    // From scope - parameters already provided
                    $token->setParameters($account);
                    $token->setText($account['name'] ?? 'Account');
                } elseif ($account) {
                    // From setTokens - extract from model
                    $token->setParameters([
                        'id' => $account->id,
                        'name' => $account->title,
                    ]);
                    $token->setText($account->title);
                } else {
                    // No data - use existing parameters
                    $token->setText($token->getParameter('name') ?? 'Account');
                }
            }),
            SpotlightScopeToken::make('tag', function ($token, $tag = null) {
                // Handle both model objects (from setTokens) and arrays (from scopes)
                if (is_array($tag)) {
                    // From scope - parameters already provided
                    $token->setParameters($tag);
                    $token->setText($tag['name'] ?? 'Tag');
                } elseif ($tag) {
                    // From setTokens - extract from model
                    $token->setParameters([
                        'id' => $tag->id,
                        'name' => $tag->name,
                    ]);
                    $token->setText($tag->name);
                } else {
                    // No data - use existing parameters
                    $token->setText($token->getParameter('name') ?? 'Tag');
                }
            }),
            SpotlightScopeToken::make('event', function ($token, $event = null) {
                // Handle both model objects (from setTokens) and arrays (from scopes)
                if (is_array($event)) {
                    // From scope - parameters already provided
                    $token->setParameters($event);
                    $token->setText($event['display'] ?? 'Event');
                } elseif ($event) {
                    // From setTokens - extract from model
                    $token->setParameters([
                        'id' => $event->id,
                        'display' => format_action_title($event->action) . ' • ' . $event->time->format('M j, g:ia'),
                        'action' => $event->action,
                        'service' => $event->service,
                    ]);
                    $token->setText(format_action_title($event->action) . ' • ' . $event->time->format('M j, g:ia'));
                } else {
                    // No data - use existing parameters
                    $token->setText($token->getParameter('display') ?? 'Event');
                }
            }),
            SpotlightScopeToken::make('object', function ($token, $object = null) {
                // Handle both model objects (from setTokens) and arrays (from scopes)
                if (is_array($object)) {
                    // From scope - parameters already provided
                    $token->setParameters($object);
                    $token->setText($object['title'] ?? 'Object');
                } elseif ($object) {
                    // From setTokens - extract from model
                    $token->setParameters([
                        'id' => $object->id,
                        'title' => $object->title ?? 'Untitled',
                        'type' => $object->type,
                        'concept' => $object->concept,
                    ]);
                    $token->setText($object->title ?? 'Untitled');
                } else {
                    // No data - use existing parameters
                    $token->setText($token->getParameter('title') ?? 'Object');
                }
            }),
            SpotlightScopeToken::make('block', function ($token, $block = null) {
                // Handle both model objects (from setTokens) and arrays (from scopes)
                if (is_array($block)) {
                    // From scope - parameters already provided
                    $token->setParameters($block);
                    $token->setText($block['display'] ?? $block['title'] ?? 'Block');
                } elseif ($block) {
                    // From setTokens - extract from model
                    $blockTitle = $block->title ?? ucfirst(str_replace('_', ' ', $block->block_type ?? 'Block'));
                    $token->setParameters([
                        'id' => $block->id,
                        'display' => $blockTitle . ($block->block_type ? ' • ' . ucfirst(str_replace('_', ' ', $block->block_type)) : ''),
                        'title' => $blockTitle,
                        'block_type' => $block->block_type,
                    ]);
                    $token->setText($blockTitle);
                } else {
                    // No data - use existing parameters
                    $token->setText($token->getParameter('display') ?? 'Block');
                }
            }),
        );
    }

    /**
     * Register Spotlight scopes (auto-applied tokens based on routes).
     */
    protected function registerScopes(): void
    {
        Spotlight::registerScopes(
            MetricDetailScope::make(),
            IntegrationDetailScope::make(),
            FinancialAccountScope::make(),
            EventDetailScope::make(),
            ObjectDetailScope::make(),
            BlockDetailScope::make(),
        );
    }

    /**
     * Register Spotlight queries (search and navigation).
     */
    protected function registerQueries(): void
    {
        // Navigation queries
        Spotlight::registerQueries(
            DailyNavigationQuery::make(),
            CoreNavigationQuery::make(),
            FetchNavigationQuery::make(),
            SettingsNavigationQuery::make(),
            AdminNavigationQuery::make(),
            HelpQuery::make(),
        );

        // Search queries
        Spotlight::registerQueries(
            SemanticSearchQuery::make(),
            EventSearchQuery::make(),
            ObjectSearchQuery::make(),
            BlockSearchQuery::make(),
            TagSearchQuery::make(),
            MetricSearchQuery::make(),
            FinancialAccountSearchQuery::make(),
        );

        // Action queries
        Spotlight::registerQueries(
            GlobalActionsQuery::make(),
            ContextualActionsQuery::make(),
            BookmarkUrlQuery::make(),
        );

        // Integration queries
        Spotlight::registerQueries(
            IntegrationSearchQuery::make(),
            PluginCommandsQuery::make(),
        );

        // Token-scoped queries
        Spotlight::registerQueries(
            MetricTrendsQuery::make(),
            IntegrationEventsQuery::make(),
        );

        // Event-scoped queries
        Spotlight::registerQueries(
            EventActionsQuery::make(),
            EventActorQuery::make(),
            EventTargetQuery::make(),
            EventBlocksQuery::make(),
            EventIntegrationQuery::make(),
        );

        // Object-scoped queries
        Spotlight::registerQueries(
            ObjectActionsQuery::make(),
            ObjectEventsQuery::make(),
            ObjectIntegrationQuery::make(),
        );

        // Block-scoped queries
        Spotlight::registerQueries(
            BlockActionsQuery::make(),
            BlockEventQuery::make(),
            BlockRelatedBlocksQuery::make(),
        );

        // Account-scoped queries
        Spotlight::registerQueries(
            AccountActionsQuery::make(),
            AccountEventsQuery::make(),
            AccountIntegrationQuery::make(),
        );

        // Integration-scoped queries
        Spotlight::registerQueries(
            IntegrationActionsQuery::make(),
            IntegrationObjectsQuery::make(),
            IntegrationBlocksQuery::make(),
        );

        // Metric-scoped queries
        Spotlight::registerQueries(
            MetricActionsQuery::make(),
            MetricEventsQuery::make(),
            MetricAnomaliesQuery::make(),
        );
    }

    /**
     * Register helpful tips shown in Spotlight footer.
     */
    protected function registerTips(): void
    {
        Spotlight::registerTips(
            'Press <kbd>></kbd> to see all available actions',
            'Press <kbd>#</kbd> to search tags',
            'Press <kbd>$</kbd> to search metrics',
            'Press <kbd>@</kbd> to search integrations',
            'Press <kbd>!</kbd> for admin commands',
            'Press <kbd>Tab</kbd> to filter results by category',
            'Press <kbd>Tab</kbd> on a result to scope into it and see related items',
            'On detail pages, context-aware commands appear automatically',
            'Navigate from events to blocks, objects, and integrations',
            'Press <kbd>Cmd+K</kbd> or click Search to open Spotlight',
            'Use arrow keys to navigate, Enter to select',
            'Press <kbd>Escape</kbd> to close Spotlight',
            'Semantic search automatically finds similar events using AI (3+ words)',
        );
    }
}
