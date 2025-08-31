<?php

namespace App\Http\Controllers;

use App\Integrations\PluginRegistry;
use App\Jobs\Migrations\StartIntegrationMigration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class IntegrationController extends Controller
{
    public function oauth(string $service)
    {
        Log::info('OAuth method called', [
            'service' => $service,
            'user_id' => Auth::id(),
            'session_id' => session()->getId(),
            'request_url' => request()->url(),
            'request_method' => request()->method(),
        ]);

        $pluginClass = PluginRegistry::getPlugin($service);
        if (! $pluginClass) {
            abort(404);
        }

        $plugin = new $pluginClass;
        $user = Auth::user();

        try {
            // For GoCardless, we need to find the existing group from the session
            if ($service === 'gocardless') {
                Log::info('GoCardless OAuth flow started', [
                    'user_id' => $user->id,
                    'session_id' => session()->getId(),
                ]);

                // Find the most recent GoCardless group for this user
                $group = IntegrationGroup::where('user_id', $user->id)
                    ->where('service', 'gocardless')
                    ->latest()
                    ->first();

                Log::info('GoCardless group lookup result', [
                    'group_found' => $group ? true : false,
                    'group_id' => $group ? $group->id : null,
                    'group_service' => $group ? $group->service : null,
                ]);

                if (! $group) {
                    throw new Exception('No GoCardless integration group found. Please start from the beginning.');
                }

                // Check if we have an institution selected
                $institutionId = session('gocardless_institution_id_' . $group->id);

                Log::info('GoCardless institution ID from session', [
                    'group_id' => $group->id,
                    'institution_id' => $institutionId,
                    'session_key' => 'gocardless_institution_id_' . $group->id,
                    'session_id' => session()->getId(),
                ]);

                if (! $institutionId) {
                    throw new Exception('No bank institution selected. Please select a bank first.');
                }

                Log::info('GoCardless OAuth flow using existing group', [
                    'service' => $service,
                    'user_id' => $user->id,
                    'group_id' => $group->id,
                    'institution_id' => $institutionId,
                ]);
            } else {
                // For other services, create a new auth group and start OAuth
                if ($plugin instanceof \App\Integrations\Contracts\OAuthIntegrationPlugin) {
                    $group = $plugin->initializeGroup($user);
                } else {
                    throw new Exception('Plugin does not support OAuth');
                }
            }

            $oauthUrl = $plugin->getOAuthUrl($group);

            Log::info('OAuth flow initiated', [
                'service' => $service,
                'user_id' => $user->id,
                'group_id' => isset($group) ? $group->id : null,
                'oauth_url' => $oauthUrl,
            ]);

            // Ensure we're redirecting to an external URL (GoCardless's OAuth)
            if (! filter_var($oauthUrl, FILTER_VALIDATE_URL)) {
                throw new Exception('Invalid OAuth URL generated');
            }

            // Set proper headers to prevent CORS issues
            return redirect($oauthUrl)
                ->header('Access-Control-Allow-Origin', '*')
                ->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
        } catch (Exception $e) {
            Log::error('OAuth flow failed', [
                'service' => $service,
                'user_id' => $user->id,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()->route('integrations.index')
                ->with('error', 'Failed to initiate OAuth flow: ' . $e->getMessage());
        }
    }

    public function oauthCallback(Request $request, string $service)
    {
        $pluginClass = PluginRegistry::getPlugin($service);
        if (! $pluginClass) {
            abort(404);
        }

        $plugin = new $pluginClass;
        /** @var User $user */
        $user = Auth::user();

        // Handle GoCardless differently since it doesn't use state parameter
        if ($service === 'gocardless') {
            // For GoCardless, find the group by the reference from the callback
            $ref = $request->get('ref');
            if ($ref) {
                // The ref parameter from GoCardless is actually the reference field, not the requisition ID
                // We need to find the group that has this reference stored
                $group = IntegrationGroup::query()
                    ->where('user_id', $user->id)
                    ->where('service', $service)
                    ->where('auth_metadata->gocardless_reference', $ref)
                    ->first();

                if (! $group) {
                    Log::error('GoCardless OAuth callback: no group found with reference', [
                        'service' => $service,
                        'user_id' => $user->id,
                        'reference' => $ref,
                    ]);

                    return redirect()->route('integrations.index')
                        ->with('error', 'No GoCardless integration found with this reference. Please start over.');
                }
            } else {
                // Use the dedicated session key to avoid "latest group" ambiguity
                $oauthGroupId = session('gocardless_oauth_group_id');
                if ($oauthGroupId) {
                    $group = IntegrationGroup::query()
                        ->where('id', $oauthGroupId)
                        ->where('user_id', $user->id)
                        ->where('service', $service)
                        ->first();

                    if ($group) {
                        Log::info('GoCardless OAuth callback: found group from session', [
                            'service' => $service,
                            'user_id' => $user->id,
                            'group_id' => $group->id,
                            'session_key' => 'gocardless_oauth_group_id',
                        ]);
                    }
                }

                // Fallback to most recent group only if session lookup failed
                if (! $group) {
                    Log::warning('GoCardless OAuth callback: session lookup failed, falling back to latest group', [
                        'service' => $service,
                        'user_id' => $user->id,
                        'session_oauth_group_id' => $oauthGroupId,
                    ]);

                    $group = IntegrationGroup::query()
                        ->where('user_id', $user->id)
                        ->where('service', $service)
                        ->latest()
                        ->first();

                    if (! $group) {
                        Log::error('GoCardless OAuth callback: no group found for user', [
                            'service' => $service,
                            'user_id' => $user->id,
                        ]);

                        return redirect()->route('integrations.index')
                            ->with('error', 'No GoCardless integration found. Please start over.');
                    }
                }
            }
        } else {
            // Standard OAuth flow with state parameter
            $state = $request->get('state');
            if (! $state) {
                Log::error('OAuth callback missing state parameter', [
                    'service' => $service,
                    'user_id' => $user->id,
                ]);

                return redirect()->route('integrations.index')
                    ->with('error', 'Invalid OAuth callback: missing state parameter');
            }

            try {
                $stateData = decrypt($state);
                $groupId = $stateData['group_id'] ?? null;

                if (! $groupId) {
                    Log::error('OAuth callback missing group_id in state', [
                        'service' => $service,
                        'user_id' => $user->id,
                        'state_data' => $stateData,
                    ]);

                    return redirect()->route('integrations.index')
                        ->with('error', 'Invalid OAuth callback: missing group ID');
                }

                // Get the specific group from the state
                $group = IntegrationGroup::query()
                    ->where('id', $groupId)
                    ->where('user_id', $user->id)
                    ->where('service', $service)
                    ->firstOrFail();

            } catch (Exception $e) {
                Log::error('OAuth callback state decryption failed', [
                    'service' => $service,
                    'user_id' => $user->id,
                    'exception' => $e->getMessage(),
                ]);

                return redirect()->route('integrations.index')
                    ->with('error', 'Invalid OAuth callback: state decryption failed');
            }
        }

        try {
            if (method_exists($plugin, 'handleOAuthCallback')) {
                $plugin->handleOAuthCallback($request, $group);
            }

            // Clean up GoCardless session data after successful OAuth
            if ($service === 'gocardless') {
                session()->forget([
                    'gocardless_oauth_group_id',
                    'gocardless_institution_id_' . $group->id,
                ]);

                Log::info('GoCardless OAuth callback: session data cleaned up', [
                    'service' => $service,
                    'user_id' => $user->id,
                    'group_id' => $group->id,
                ]);
            }

            // Redirect to onboarding to select instance types
            return redirect()->route('integrations.onboarding', ['group' => $group->id])
                ->with('success', 'Connected! Now choose what to track.');
        } catch (Exception $e) {
            // Log the full exception details for debugging
            Log::error('OAuth callback failed', [
                'service' => $service,
                'user_id' => $user->id,
                'group_id' => $group->id ?? null,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()->route('integrations.index')
                ->with('error', 'Failed to connect integration. Please try again or contact support if the problem persists.');
        }
    }

    public function initialize(string $service)
    {
        $pluginClass = PluginRegistry::getPlugin($service);
        if (! $pluginClass) {
            abort(404);
        }

        $plugin = new $pluginClass;
        $user = Auth::user();

        try {
            if (method_exists($plugin, 'initializeGroup')) {
                $group = $plugin->initializeGroup($user);
            } else {
                // Back-compat path
                $integration = $plugin->initialize($user);
                $group = IntegrationGroup::create([
                    'user_id' => $integration->user_id,
                    'service' => $integration->service,
                    'account_id' => $integration->account_id,
                    'access_token' => $integration->access_token,
                ]);
                $integration->update(['integration_group_id' => $group->id]);
            }

            // For GoCardless, redirect to bank selection first
            if ($service === 'gocardless') {
                return redirect()->route('integrations.gocardless.bankSelection', ['group' => $group->id])
                    ->with('success', 'Integration initialized! Select your bank to continue.');
            }

            return redirect()->route('integrations.onboarding', ['group' => $group->id])
                ->with('success', 'Integration initialized! Configure instances next.');
        } catch (Exception $e) {
            // Log the full exception details for debugging
            Log::error('Integration initialization failed', [
                'service' => $service,
                'user_id' => $user->id,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()->route('integrations.index')
                ->with('error', 'Failed to initialize integration. Please try again or contact support if the problem persists.');
        }
    }

    public function onboarding(IntegrationGroup $group)
    {
        // Authorization
        if ((string) $group->user_id !== (string) Auth::id()) {
            abort(403);
        }
        $pluginClass = PluginRegistry::getPlugin($group->service);
        $pluginName = $pluginClass ? $pluginClass::getDisplayName() : ucfirst($group->service);
        $types = $pluginClass ? $pluginClass::getInstanceTypes() : [];

        // Get available accounts for GoCardless onboarding
        $availableAccounts = [];
        if ($group->service === 'gocardless' && $pluginClass) {
            $plugin = new $pluginClass;
            if (method_exists($plugin, 'getAvailableAccountsForOnboarding')) {
                $availableAccounts = $plugin->getAvailableAccountsForOnboarding($group);
            }
        }

        return view('livewire.integrations.onboarding', [
            'group' => $group,
            'pluginName' => $pluginName,
            'types' => $types,
            'availableAccounts' => $availableAccounts,
        ]);
    }

    public function storeInstances(Request $request, IntegrationGroup $group)
    {
        if ((string) $group->user_id !== (string) Auth::id()) {
            abort(403);
        }
        $pluginClass = PluginRegistry::getPlugin($group->service);
        if (! $pluginClass) {
            abort(404);
        }
        $plugin = new $pluginClass;
        // Allowed instance types from plugin
        $typesMeta = method_exists($pluginClass, 'getInstanceTypes') ? $pluginClass::getInstanceTypes() : [];
        $allowedTypes = array_keys($typesMeta);

        // Build validation rules
        $rules = [
            'types' => ['required', 'array', 'min:1'],
            'types.*' => ['string', Rule::in($allowedTypes)],
            'config' => ['array'],
            'migration_timebox_minutes' => ['nullable', 'integer', 'min:1'],
        ];

        // Ensure mandatory types are included
        $mandatoryTypes = [];
        foreach ($typesMeta as $typeKey => $typeMeta) {
            if (($typeMeta['mandatory'] ?? false) === true) {
                $mandatoryTypes[] = $typeKey;
            }
        }

        // Add per-field rules based on schema for each allowed type
        foreach ($allowedTypes as $typeKey) {
            $schema = $typesMeta[$typeKey]['schema'] ?? [];
            foreach ($schema as $field => $fieldConfig) {
                $fieldRules = [];
                $isRequired = (bool) ($fieldConfig['required'] ?? false);
                $fieldType = $fieldConfig['type'] ?? 'string';
                $min = $fieldConfig['min'] ?? null;

                $fieldRules[] = $isRequired ? 'required' : 'nullable';
                switch ($fieldType) {
                    case 'integer':
                        $fieldRules[] = 'integer';
                        if ($min !== null) {
                            $fieldRules[] = 'min:' . $min;
                        }
                        break;
                    case 'array':
                        $fieldRules[] = 'array';
                        break;
                    default:
                        $fieldRules[] = 'string';
                        break;
                }
                $rules["config.{$typeKey}.{$field}"] = $fieldRules;
            }
        }

        $data = $request->validate($rules);

        // Ensure mandatory types are included
        $selectedTypes = $data['types'];
        foreach ($mandatoryTypes as $mandatoryType) {
            if (! in_array($mandatoryType, $selectedTypes)) {
                $selectedTypes[] = $mandatoryType;
            }
        }
        $data['types'] = $selectedTypes;

        // Read optional migration timebox from validated data
        $timeboxMinutes = $data['migration_timebox_minutes'] ?? null;

        // Only keep config entries for selected types
        $data['config'] = Arr::only(($data['config'] ?? []), $selectedTypes);
        foreach ($data['types'] as $type) {
            $initial = $data['config'][$type] ?? [];
            // Keep update_frequency_minutes in configuration
            // The database column is now optional and will be read from configuration
            // Normalize schema-declared array fields that may arrive as strings
            $schemaForType = $typesMeta[$type]['schema'] ?? [];
            foreach ($schemaForType as $field => $fieldConfig) {
                if (($fieldConfig['type'] ?? null) === 'array' && isset($initial[$field]) && is_string($initial[$field])) {
                    $raw = $initial[$field];
                    $decoded = json_decode($raw, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $initial[$field] = array_values(array_filter(array_map('trim', $decoded)));
                    } else {
                        $parts = preg_split('/[,\n]/', $raw) ?: [];
                        $initial[$field] = array_values(array_filter(array_map('trim', $parts)));
                    }
                }
            }

            if (method_exists($plugin, 'createInstance')) {
                // For GoCardless, create one instance per account for each type
                if ($group->service === 'gocardless' && method_exists($plugin, 'getAvailableAccountsForOnboarding')) {
                    $accounts = $plugin->getAvailableAccountsForOnboarding($group);

                    foreach ($accounts as $account) {
                        // Create instance-specific config with account details
                        $instanceConfig = $initial;
                        $instanceConfig['account_id'] = $account['id'];
                        $instanceConfig['account_name'] = $account['name'] ?? 'Unknown Account';

                        // Create a unique name for each account instance
                        if (isset($account['details']) && ! empty($account['details'])) {
                            $accountName = $account['details'];
                        } elseif (isset($account['ownerName'])) {
                            $accountName = $account['ownerName'] . "'s Account";
                        } else {
                            $accountName = 'Account ' . substr($account['resourceId'] ?? $account['id'], 0, 8);
                        }

                        $typeName = $initial['name'] ?? $typesMeta[$type]['label'] ?? ucfirst($type);
                        $customName = "{$typeName} - {$accountName}";

                        $instance = $plugin->createInstance($group, $type, $instanceConfig);
                        $instance->update(['name' => $customName]);

                        // Optional historical migration trigger
                        if ($request->boolean('run_migration')) {
                            $timeboxUntil = $timeboxMinutes ? now()->addMinutes($timeboxMinutes) : null;
                            StartIntegrationMigration::dispatch($instance, $timeboxUntil)
                                ->onConnection('redis')
                                ->onQueue('migration');
                        }
                    }
                } else {
                    // Standard single instance creation for other services
                    $customName = $initial['name'] ?? null;
                    if (array_key_exists('name', $initial)) {
                        unset($initial['name']);
                    }
                    $instance = $plugin->createInstance($group, $type, $initial);
                    if ($customName) {
                        $instance->update(['name' => $customName]);
                    }

                    // Optional historical migration trigger
                    if ($request->boolean('run_migration')) {
                        $timeboxUntil = $timeboxMinutes ? now()->addMinutes($timeboxMinutes) : null;
                        StartIntegrationMigration::dispatch($instance, $timeboxUntil)
                            ->onConnection('redis')
                            ->onQueue('migration');
                    }
                }
            }
        }
        // Customize success message for GoCardless multi-account scenarios
        $successMessage = 'Instances created successfully.';
        if ($group->service === 'gocardless' && method_exists($plugin, 'getAvailableAccountsForOnboarding')) {
            $accounts = $plugin->getAvailableAccountsForOnboarding($group);
            $accountCount = count($accounts);
            if ($accountCount > 1) {
                $successMessage = "Created {$accountCount} account instances successfully!";
            }
        }

        return redirect()->route('integrations.index')
            ->with('success', $successMessage);
    }
}
