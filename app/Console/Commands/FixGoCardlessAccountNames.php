<?php

namespace App\Console\Commands;

use App\Integrations\GoCardless\GoCardlessBankPlugin;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FixGoCardlessAccountNames extends Command
{
    protected $signature = 'gocardless:fix-account-names {--dry-run : Show what would be fixed without making changes}';

    protected $description = 'Fix GoCardless account objects that have placeholder names like "Account XXXXX" and merge duplicates';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('🔍 DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        // Find all GoCardless account objects with placeholder names
        $brokenAccounts = EventObject::where('concept', 'account')
            ->where('type', 'bank_account')
            ->where('title', 'LIKE', 'Account %')
            ->get();

        if ($brokenAccounts->isEmpty()) {
            $this->info('✅ No broken account names found!');

            return self::SUCCESS;
        }

        $this->info("Found {$brokenAccounts->count()} account(s) with placeholder names:");
        $this->newLine();

        $fixed = 0;
        $merged = 0;
        $failed = 0;

        foreach ($brokenAccounts as $account) {
            $this->line("Processing: {$account->title} (ID: " . substr($account->id, 0, 8) . '...)');

            $integrationId = $account->metadata['integration_id'] ?? null;
            $accountId = $account->metadata['account_id'] ?? null;

            if (! $integrationId) {
                $this->error("  ❌ No integration_id found in metadata, skipping");
                $failed++;
                continue;
            }

            // Check if this is an onboarding integration ID
            if (str_starts_with($integrationId, 'onboarding_')) {
                // Extract group_id and account_id from onboarding integration ID
                // Format: onboarding_{group_id}_{account_id}
                $parts = explode('_', $integrationId);
                if (count($parts) >= 3) {
                    $groupId = $parts[1];
                    $extractedAccountId = implode('_', array_slice($parts, 2));

                    $this->line("  📋 Onboarding object detected");
                    $this->line("  🔑 Extracted account_id: {$extractedAccountId}");

                    // Find the real integration for this group
                    $integration = Integration::whereHas('group', function ($query) use ($groupId) {
                        $query->where('id', $groupId);
                    })->first();

                    if (! $integration) {
                        $this->error("  ❌ No integration found for group {$groupId}, skipping");
                        $failed++;
                        continue;
                    }

                    $accountId = $extractedAccountId;
                } else {
                    $this->error("  ❌ Invalid onboarding integration ID format, skipping");
                    $failed++;
                    continue;
                }
            } else {
                // Regular integration ID
                $integration = Integration::find($integrationId);

                if (! $integration) {
                    $this->error("  ❌ Integration {$integrationId} not found, skipping");
                    $failed++;
                    continue;
                }
            }

            // If we still don't have account_id, try to extract from account content
            if (! $accountId && $account->content) {
                $contentData = json_decode($account->content, true);
                $accountId = $contentData['id'] ?? null;
            }

            if (! $accountId) {
                $this->error("  ❌ No account_id available, skipping");
                $failed++;
                continue;
            }

            // *** CHECK FOR EXISTING PROPER ACCOUNT OBJECT ***
            $this->line("  🔍 Checking for existing proper account object with account_id: {$accountId}");

            $existingGoodAccount = EventObject::where('concept', 'account')
                ->where('type', 'bank_account')
                ->where('id', '!=', $account->id) // Exclude the current placeholder
                ->whereJsonContains('metadata->account_id', $accountId)
                ->first();

            if ($existingGoodAccount) {
                $this->line("  ✨ Found existing proper account: {$existingGoodAccount->title} (ID: " . substr($existingGoodAccount->id, 0, 8) . '...)');

                // Count events and blocks that need to be moved
                $eventsCount = Event::where('actor_id', $account->id)->count();
                $blocksCount = Block::whereHas('event', function ($query) use ($account) {
                    $query->where('actor_id', $account->id);
                })->count();

                $this->line("  📦 Events to merge: {$eventsCount}");
                $this->line("  📦 Blocks to merge: {$blocksCount}");

                if (! $dryRun) {
                    // Merge: Move all events and blocks from placeholder to good account
                    DB::transaction(function () use ($account, $existingGoodAccount) {
                        // Update all events where this placeholder is the actor
                        Event::where('actor_id', $account->id)
                            ->update(['actor_id' => $existingGoodAccount->id]);

                        // Update all events where this placeholder is the target
                        Event::where('target_id', $account->id)
                            ->update(['target_id' => $existingGoodAccount->id]);

                        // Soft delete the placeholder account
                        $account->delete();
                    });

                    $this->info("  ✅ Merged placeholder into existing account and deleted placeholder");

                    Log::info('Merged GoCardless duplicate account objects', [
                        'placeholder_id' => $account->id,
                        'placeholder_name' => $account->title,
                        'good_account_id' => $existingGoodAccount->id,
                        'good_account_name' => $existingGoodAccount->title,
                        'account_id' => $accountId,
                        'events_moved' => $eventsCount,
                        'blocks_moved' => $blocksCount,
                    ]);

                    $merged++;
                } else {
                    $this->info("  ✅ Would merge placeholder into '{$existingGoodAccount->title}' and delete placeholder");
                    $merged++;
                }
            } else {
                // No existing good account found, fix this one in place
                $this->line("  🔄 No existing proper account found, fetching details from GoCardless...");

                try {
                    $plugin = new GoCardlessBankPlugin;
                    $accountDetails = $plugin->getAccount($accountId);

                    if (! $accountDetails) {
                        $this->error("  ❌ Failed to fetch account details from GoCardless API");
                        $failed++;
                        continue;
                    }

                    // Add the account ID to the details
                    $accountDetails['id'] = $accountId;

                    // Generate the proper account name
                    $properName = GoCardlessBankPlugin::generateAccountName($accountDetails);

                    $this->line("  ✨ Proper name: {$properName}");

                    if (! $dryRun) {
                        // Update the account object
                        $accountType = $plugin->mapCashAccountType($accountDetails['cashAccountType'] ?? null);

                        $account->update([
                            'title' => $properName,
                            'content' => json_encode($accountDetails),
                            'metadata' => array_merge($account->metadata ?? [], [
                                'account_id' => $accountId,
                                'name' => $properName,
                                'provider' => $plugin->deriveProviderName($integration->group, $accountDetails),
                                'account_type' => $accountType,
                                'currency' => $accountDetails['currency'] ?? 'GBP',
                                'account_number' => $plugin->deriveAccountNumber($accountDetails),
                                'raw' => $accountDetails,
                            ]),
                        ]);

                        $this->info("  ✅ Fixed in place: {$account->title} → {$properName}");

                        Log::info('Fixed GoCardless account name in place', [
                            'account_object_id' => $account->id,
                            'old_name' => $account->title,
                            'new_name' => $properName,
                            'account_id' => $accountId,
                            'integration_id' => $integration->id,
                        ]);

                        $fixed++;
                    } else {
                        $this->info("  ✅ Would fix in place: {$account->title} → {$properName}");
                        $fixed++;
                    }
                } catch (\Exception $e) {
                    $this->error("  ❌ Error: {$e->getMessage()}");
                    $failed++;
                }
            }

            $this->newLine();
        }

        $this->newLine();

        if ($dryRun) {
            $this->info("📊 Summary (DRY RUN):");
            $this->info("  Would fix in place: {$fixed}");
            $this->info("  Would merge duplicates: {$merged}");
            $this->info("  Would fail: {$failed}");
            $this->newLine();
            $this->info("Run without --dry-run to apply changes");
        } else {
            $this->info("📊 Summary:");
            $this->info("  ✅ Fixed in place: {$fixed}");
            $this->info("  🔗 Merged duplicates: {$merged}");
            $this->info("  ❌ Failed: {$failed}");
        }

        return self::SUCCESS;
    }
}
