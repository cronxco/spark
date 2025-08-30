<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Get all accounts that need metadata updates
        $accounts = DB::table('objects')
            ->where('concept', 'account')
            ->whereIn('type', ['monzo_account', 'monzo_pot', 'bank_account'])
            ->get();

        foreach ($accounts as $account) {
            $metadata = $account->metadata ? json_decode($account->metadata, true) : [];
            $updated = false;

            // Update Monzo accounts
            if ($account->type === 'monzo_account') {
                if (empty($metadata['name'])) {
                    $metadata['name'] = 'Monzo Account';
                    $updated = true;
                }
                if (empty($metadata['provider'])) {
                    $metadata['provider'] = 'Monzo';
                    $updated = true;
                }
                if (empty($metadata['account_type'])) {
                    $metadata['account_type'] = 'current_account';
                    $updated = true;
                }
                if (empty($metadata['currency'])) {
                    $metadata['currency'] = 'GBP';
                    $updated = true;
                }
            }

            // Update Monzo pots
            if ($account->type === 'monzo_pot') {
                if (empty($metadata['name'])) {
                    $metadata['name'] = 'Monzo Pot';
                    $updated = true;
                }
                if (empty($metadata['provider'])) {
                    $metadata['provider'] = 'Monzo';
                    $updated = true;
                }
                if (empty($metadata['account_type'])) {
                    $metadata['account_type'] = 'savings_account';
                    $updated = true;
                }
                if (empty($metadata['currency'])) {
                    $metadata['currency'] = 'GBP';
                    $updated = true;
                }
                
                // Ensure pot has a proper title if it's missing
                if (empty($account->title) || $account->title === 'Pot') {
                    DB::table('objects')
                        ->where('id', $account->id)
                        ->update(['title' => 'Monzo Pot']);
                }
            }

            // Update GoCardless accounts
            if ($account->type === 'bank_account') {
                if (empty($metadata['name'])) {
                    $metadata['name'] = 'Bank Account';
                    $updated = true;
                }
                if (empty($metadata['provider'])) {
                    $metadata['provider'] = 'GoCardless';
                    $updated = true;
                }
                if (empty($metadata['account_type'])) {
                    $metadata['account_type'] = 'other';
                    $updated = true;
                }
                if (empty($metadata['currency'])) {
                    $metadata['currency'] = 'GBP';
                    $updated = true;
                }
            }

            // Update the database if changes were made
            if ($updated) {
                DB::table('objects')
                    ->where('id', $account->id)
                    ->update(['metadata' => json_encode($metadata)]);
            }
        }
    }

    public function down(): void
    {
        // This migration adds metadata, so reversing would remove it
        // We don't want to remove metadata as it would break the display
        // No down migration needed
    }
};
