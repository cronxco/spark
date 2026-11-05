<?php

namespace App\Console\Commands;

use App\Integrations\PluginRegistry;
use Illuminate\Console\Command;

class ShowIntegrationConfig extends Command
{
    protected $signature = 'integrations:config {plugin_identifier?}';

    protected $description = 'Show integration plugin configuration details';

    public function handle(): void
    {
        $pluginIdentifier = $this->argument('plugin_identifier');

        if ($pluginIdentifier) {
            $this->showPluginConfig($pluginIdentifier);
        } else {
            $this->showAllPlugins();
        }
    }

    private function showAllPlugins(): void
    {
        $plugins = PluginRegistry::getPluginsWithConfig();

        $this->info("Available Integration Plugins ({$plugins->count()} total):\n");

        $plugins->each(function ($plugin) {
            $actionCount = count($plugin['action_types']);
            $blockCount = count($plugin['block_types']);
            $objectCount = count($plugin['object_types']);

            $this->line("🔹 {$plugin['display_name']} ({$plugin['identifier']})");
            $this->line("   Icon: {$plugin['icon']} | Color: {$plugin['accent_color']} | Domain: {$plugin['domain']} | Service: {$plugin['service_type']}");
            $this->line("   Actions: {$actionCount} | Blocks: {$blockCount} | Objects: {$objectCount}");
            $this->line("   {$plugin['description']}\n");
        });

        $this->line("Use 'php artisan integrations:config {plugin_identifier}' to see detailed configuration for a specific plugin.");
    }

    private function showPluginConfig(string $pluginIdentifier): void
    {
        $config = PluginRegistry::getPluginConfig($pluginIdentifier);

        if (! $config) {
            $this->error("Plugin '{$pluginIdentifier}' not found.");

            return;
        }

        $this->info("Configuration for {$config['display_name']} ({$pluginIdentifier}):");
        $this->line("Icon: {$config['icon']}");
        $this->line("Accent Color: {$config['accent_color']}");
        $this->line("Domain: {$config['domain']}");
        $this->line("Service Type: {$config['service_type']}");
        $this->line("Description: {$config['description']}\n");

        // Action Types
        $this->info('Action Types:');
        foreach ($config['action_types'] as $key => $action) {
            $objectText = $action['display_with_object'] ? 'with object' : 'without object';
            $unitText = $action['value_unit'] ? $action['value_unit'] : 'no unit';
            $hiddenText = $action['hidden'] ? ' (hidden)' : '';

            $this->line("  • {$action['display_name']} ({$action['icon']}) - {$objectText}, {$unitText}{$hiddenText}");
            $this->line("    {$action['description']}");
        }

        $this->line('');

        // Block Types
        $this->info('Block Types:');
        foreach ($config['block_types'] as $key => $block) {
            $objectText = $block['display_with_object'] ? 'with object' : 'without object';
            $unitText = $block['value_unit'] ? $block['value_unit'] : 'no unit';
            $hiddenText = $block['hidden'] ? ' (hidden)' : '';

            $this->line("  • {$block['display_name']} ({$block['icon']}) - {$objectText}, {$unitText}{$hiddenText}");
            $this->line("    {$block['description']}");
        }

        $this->line('');

        // Object Types
        $this->info('Object Types:');
        foreach ($config['object_types'] as $key => $object) {
            $hiddenText = $object['hidden'] ? ' (hidden)' : '';

            $this->line("  • {$object['display_name']} ({$object['icon']}){$hiddenText}");
            $this->line("    {$object['description']}");
        }

        $this->line('');

        // Instance Types
        $this->info('Instance Types:');
        foreach ($config['instance_types'] as $key => $instance) {
            $this->line("  • {$key}: {$instance['label']}");
        }
    }
}
