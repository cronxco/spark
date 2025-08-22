<?php

namespace App\Http\Controllers;

use App\Jobs\SyncGCAccounts;
use App\Jobs\SyncGCBalances;
use App\Jobs\SyncGCTransactions;
use App\Models\User;
use CronxCo\DataModel\DataModelFacade as DataModel;
use DateTime;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Nordigen\NordigenPHP\API\NordigenClient;

class GCBankController extends Controller
{
    public function sync()
    {
        Log::debug('Starting GoCardless processing');
        $user = User::findOrFail(1);

        $client = new NordigenClient(config('services.gc_bank.client_id'), config('services.gc_bank.client_secret'));

        if (! empty($user->gc_bank_token_expires)) {
            if (Carbon::now()->gt(Carbon::parse($user->gc_bank_refresh_expires))) {
                // Generate a new token as the refresh token has expired
                $token = $client->createAccessToken();
                $accessToken = $client->getAccessToken();

                $user = User::updateOrCreate([
                    'id' => $user->id,
                ], [
                    'gc_bank_token' => $token['access'],
                    'gc_bank_token_expires' => Carbon::now()->addSeconds($token['access_expires']),
                    'gc_bank_refresh_token' => $token['refresh'],
                    'gc_bank_refresh_expires' => Carbon::now()->addSeconds($token['refresh_expires']),
                ]);
            } elseif (Carbon::now()->gt(Carbon::parse($user->gc_bank_token_expires))) {
                // Refresh the access token
                $token = $client->refreshAccessToken($user->gc_bank_refresh_token);
                $accessToken = $client->getAccessToken();

                $user = User::updateOrCreate([
                    'id' => $user->id,
                ], [
                    'gc_bank_token' => $token['access'],
                    'gc_bank_token_expires' => Carbon::now()->addSeconds($token['access_expires']),
                ]);
            } else {
                // Use the token from the database
                $accessToken = $user->gc_bank_token;
                $token = $client->setAccessToken($user->gc_bank_token);
            }
        } else {
            $token = $client->createAccessToken();
            $accessToken = $client->getAccessToken();
            $user = User::updateOrCreate([
                'id' => $user->id,
            ], [
                'gc_bank_token' => $token['access'],
                'gc_bank_token_expires' => Carbon::now()->addSeconds($token['access_expires']),
                'gc_bank_refresh_token' => $token['refresh'],
                'gc_bank_refresh_expires' => Carbon::now()->addSeconds($token['refresh_expires']),
            ]);
        }

        // Create an array of institutions so we can match them later
        $institutions = $client->institution->getInstitutionsByCountry('GB');
        $institution_array = [];
        foreach ($institutions as $institution) {
            $institution_array[$institution['id']] = $institution['name'];
        }

        $requisitions = $client->requisition->getRequisitions();
        Log::channel('data')->debug('GoCardless Requisitions', $requisitions);

        foreach ($requisitions['results'] as $requisition) {
            SyncGCAccounts::dispatch($requisition, $institution_array);
        }
    }

    public function account($requisition, $institution_array)
    {

        $user = User::findOrFail(1);

        $client = new NordigenClient(config('services.gc_bank.client_id'), config('services.gc_bank.client_secret'));

        if (! empty($user->gc_bank_token_expires)) {
            if (Carbon::now()->gt(Carbon::parse($user->gc_bank_refresh_expires))) {
                // Generate a new token as the refresh token has expired
                $token = $client->createAccessToken();
                $accessToken = $client->getAccessToken();

                $user = User::updateOrCreate([
                    'id' => $user->id,
                ], [
                    'gc_bank_token' => $token['access'],
                    'gc_bank_token_expires' => Carbon::now()->addSeconds($token['access_expires']),
                    'gc_bank_refresh_token' => $token['refresh'],
                    'gc_bank_refresh_expires' => Carbon::now()->addSeconds($token['refresh_expires']),
                ]);
            } elseif (Carbon::now()->gt(Carbon::parse($user->gc_bank_token_expires))) {
                // Refresh the access token
                $token = $client->refreshAccessToken($user->gc_bank_refresh_token);
                $accessToken = $client->getAccessToken();

                $user = User::updateOrCreate([
                    'id' => $user->id,
                ], [
                    'gc_bank_token' => $token['access'],
                    'gc_bank_token_expires' => Carbon::now()->addSeconds($token['access_expires']),
                ]);
            } else {
                // Use the token from the database
                $accessToken = $user->gc_bank_token;
                $token = $client->setAccessToken($user->gc_bank_token);
            }
        } else {
            $token = $client->createAccessToken();
            $accessToken = $client->getAccessToken();
            $user = User::updateOrCreate([
                'id' => $user->id,
            ], [
                'gc_bank_token' => $token['access'],
                'gc_bank_token_expires' => Carbon::now()->addSeconds($token['access_expires']),
                'gc_bank_refresh_token' => $token['refresh'],
                'gc_bank_refresh_expires' => Carbon::now()->addSeconds($token['refresh_expires']),
            ]);
        }

        $accounts = [];
        $transactions = [];

        foreach ($requisition['accounts'] as $accountId) {
            Log::debug('Processing GoCardless Account ' . $accountId);
            try {
                // Get details
                $account = $client->account($accountId);
                $details = $account->getAccountDetails()['account'];
                $metadata = $account->getAccountMetaData();
                // Set names
                $institutionName = $institution_array[$metadata['institution_id']];
                $accountName = Str::kebab($institutionName) . '-' . Str::lower($details['cashAccountType']) . '-' . $metadata['iban'];
                $accounts[$accountName]['institution'] = $institution_array[$metadata['institution_id']];
                $accounts[$accountName]['name'] = str_replace('-', '_', $accountName);
                // Log
                Log::debug('GoCardless Account ' . $accountId . ' name set as ' . $accountName);
                // Set Metadata
                $accounts[$accountName] += $metadata;

                // Balances
                $balances = $account->getAccountBalances();

                foreach ($balances['balances'] as $balance) {
                    if ($balance['balanceType'] == 'interimBooked') {
                        $accounts[$accountName]['balance'] = $balance['balanceAmount']['amount'];
                        $accounts[$accountName]['balance_currency'] = $balance['balanceAmount']['currency'];
                        $accounts[$accountName]['balances'][$balance['balanceType']]['amount'] = $balance['balanceAmount']['amount'];
                        $accounts[$accountName]['balances'][$balance['balanceType']]['currency'] = $balance['balanceAmount']['currency'];
                        $accounts[$accountName]['balances'][$balance['balanceType']]['referenceDate'] = $balance['referenceDate'];
                    } elseif ($balance['balanceType'] == 'interimAvailable' && ! isset($accounts[$accountName]['balance'])) {
                        $accounts[$accountName]['balance'] = $balance['balanceAmount']['amount'];
                        $accounts[$accountName]['balance_currency'] = $balance['balanceAmount']['currency'];
                        $accounts[$accountName]['balances'][$balance['balanceType']]['amount'] = $balance['balanceAmount']['amount'];
                        $accounts[$accountName]['balances'][$balance['balanceType']]['currency'] = $balance['balanceAmount']['currency'];
                        $accounts[$accountName]['balances'][$balance['balanceType']]['referenceDate'] = $balance['referenceDate'];
                    } elseif ($balance['balanceType'] == 'information' && ! isset($accounts[$accountName]['balance'])) {
                        $accounts[$accountName]['balance'] = $balance['balanceAmount']['amount'];
                        $accounts[$accountName]['balance_currency'] = $balance['balanceAmount']['currency'];
                        $accounts[$accountName]['balances'][$balance['balanceType']]['amount'] = $balance['balanceAmount']['amount'];
                        $accounts[$accountName]['balances'][$balance['balanceType']]['currency'] = $balance['balanceAmount']['currency'];
                        $accounts[$accountName]['balances'][$balance['balanceType']]['referenceDate'] = $balance['referenceDate'];
                    } else {
                        $accounts[$accountName]['balances'][$balance['balanceType']]['amount'] = $balance['balanceAmount']['amount'];
                        $accounts[$accountName]['balances'][$balance['balanceType']]['currency'] = $balance['balanceAmount']['currency'];
                        $accounts[$accountName]['balances'][$balance['balanceType']]['referenceDate'] = $balance['referenceDate'];
                    }
                }
                $accounts[$accountName] += $details;
                if (isset($accounts[$accountName]['details'])) {
                    $accounts[$accountName]['title'] = $accounts[$accountName]['institution'] . ' ' . $accounts[$accountName]['details'];
                } else {
                    $accounts[$accountName]['title'] = $accounts[$accountName]['institution'] . ' ' . substr($accounts[$accountName]['iban'], -4);
                }

                Log::channel('data')->debug('GoCardless  ' . $accountName . ' Details', $accounts);

                SyncGCBalances::dispatch($accounts, $institution_array);

                // Get Transactions
                $transactions[$accountName] = $account->getAccountTransactions();
                Log::channel('data')->debug('GoCardless ' . $accountName . ' Transactions', $transactions);

                // Dispatch job chain
                $jobs = collect();
                $jobs->push(new SyncGCBalances($accounts));
                $jobs->push(new SyncGCTransactions($transactions, $accounts));
                Bus::chain($jobs)->dispatch();
            } catch (Exception $e) {
                Log::error('Failed getting details for ' . $accountId);
            }
        }
    }

    public function balancesOld()
    {
        $user = User::findOrFail(1);

        $client = new NordigenClient(config('services.gc_bank.client_id'), config('services.gc_bank.client_secret'));

        if (! empty($user->gc_bank_token_expires)) {
            if (Carbon::now()->gt(Carbon::parse($user->gc_bank_refresh_expires))) {
                // Generate a new token as the refresh token has expired
                $token = $client->createAccessToken();
                $accessToken = $client->getAccessToken();

                $user = User::updateOrCreate([
                    'id' => $user->id,
                ], [
                    'gc_bank_token' => $token['access'],
                    'gc_bank_token_expires' => Carbon::now()->addSeconds($token['access_expires']),
                    'gc_bank_refresh_token' => $token['refresh'],
                    'gc_bank_refresh_expires' => Carbon::now()->addSeconds($token['refresh_expires']),
                ]);
            } elseif (Carbon::now()->gt(Carbon::parse($user->gc_bank_token_expires))) {
                // Refresh the access token
                $token = $client->refreshAccessToken($user->gc_bank_refresh_token);
                $accessToken = $client->getAccessToken();

                $user = User::updateOrCreate([
                    'id' => $user->id,
                ], [
                    'gc_bank_token' => $token['access'],
                    'gc_bank_token_expires' => Carbon::now()->addSeconds($token['access_expires']),
                ]);
            } else {
                // Use the token from the database
                $accessToken = $user->gc_bank_token;
                $token = $client->setAccessToken($user->gc_bank_token);
            }
        } else {
            $token = $client->createAccessToken();
            $accessToken = $client->getAccessToken();
            $user = User::updateOrCreate([
                'id' => $user->id,
            ], [
                'gc_bank_token' => $token['access'],
                'gc_bank_token_expires' => Carbon::now()->addSeconds($token['access_expires']),
                'gc_bank_refresh_token' => $token['refresh'],
                'gc_bank_refresh_expires' => Carbon::now()->addSeconds($token['refresh_expires']),
            ]);
        }

        // Create an array of institutions so we can match them later
        $institutions = $client->institution->getInstitutionsByCountry('GB');
        $institution_array = [];
        foreach ($institutions as $institution) {
            $institution_array[$institution['id']] = $institution['name'];
        }

        $requisitions = $client->requisition->getRequisitions();
        Log::channel('data')->debug('GC Requisitions', $requisitions);

        // Generate an array of accounts from the requisitions
        $accounts = [];
        $transactions = [];
        foreach ($requisitions['results'] as $requisition) {
            foreach ($requisition['accounts'] as $accountId) {
                try {
                    $account = $client->account($accountId);
                    $details = $account->getAccountDetails()['account'];
                    $metadata = $account->getAccountMetaData();
                    $institutionName = $institution_array[$metadata['institution_id']];
                    $accountName = Str::kebab($institutionName) . '-' . Str::lower($details['cashAccountType']) . '-' . $metadata['iban'];
                    $accounts[$accountName]['institution'] = $institution_array[$metadata['institution_id']];
                    $accounts[$accountName]['name'] = str_replace('-', '_', $accountName);
                    $accounts[$accountName] += $metadata;
                    // Get Transactions
                    $transactions[$accountName] = $account->getAccountTransactions();
                    // Balances
                    foreach ($account->getAccountBalances()['balances'] as $balance) {
                        if ($balance['balanceType'] == 'interimBooked') {
                            $accounts[$accountName]['balance'] = $balance['balanceAmount']['amount'];
                            $accounts[$accountName]['balance_currency'] = $balance['balanceAmount']['currency'];
                            $accounts[$accountName]['balances'][$balance['balanceType']]['amount'] = $balance['balanceAmount']['amount'];
                            $accounts[$accountName]['balances'][$balance['balanceType']]['currency'] = $balance['balanceAmount']['currency'];
                            $accounts[$accountName]['balances'][$balance['balanceType']]['referenceDate'] = $balance['referenceDate'];
                        } elseif ($balance['balanceType'] == 'interimAvailable' && ! isset($accounts[$accountName]['balance'])) {
                            $accounts[$accountName]['balance'] = $balance['balanceAmount']['amount'];
                            $accounts[$accountName]['balance_currency'] = $balance['balanceAmount']['currency'];
                            $accounts[$accountName]['balances'][$balance['balanceType']]['amount'] = $balance['balanceAmount']['amount'];
                            $accounts[$accountName]['balances'][$balance['balanceType']]['currency'] = $balance['balanceAmount']['currency'];
                            $accounts[$accountName]['balances'][$balance['balanceType']]['referenceDate'] = $balance['referenceDate'];
                        } elseif ($balance['balanceType'] == 'information' && ! isset($accounts[$accountName]['balance'])) {
                            $accounts[$accountName]['balance'] = $balance['balanceAmount']['amount'];
                            $accounts[$accountName]['balance_currency'] = $balance['balanceAmount']['currency'];
                            $accounts[$accountName]['balances'][$balance['balanceType']]['amount'] = $balance['balanceAmount']['amount'];
                            $accounts[$accountName]['balances'][$balance['balanceType']]['currency'] = $balance['balanceAmount']['currency'];
                            $accounts[$accountName]['balances'][$balance['balanceType']]['referenceDate'] = $balance['referenceDate'];
                        } else {
                            $accounts[$accountName]['balances'][$balance['balanceType']]['amount'] = $balance['balanceAmount']['amount'];
                            $accounts[$accountName]['balances'][$balance['balanceType']]['currency'] = $balance['balanceAmount']['currency'];
                            $accounts[$accountName]['balances'][$balance['balanceType']]['referenceDate'] = $balance['referenceDate'];
                        }
                    }
                    $accounts[$accountName] += $details;
                    if (isset($accounts[$accountName]['details'])) {
                        $accounts[$accountName]['title'] = $accounts[$accountName]['institution'] . ' ' . $accounts[$accountName]['details'];
                    } else {
                        $accounts[$accountName]['title'] = $accounts[$accountName]['institution'] . ' ' . substr($accounts[$accountName]['iban'], -4);
                    }
                } catch (Exception $e) {
                    Log::error('Failed getting details for ' . $accountId);
                }
            }
        }

        Log::channel('data')->debug('GC Account Details', $accounts);
        Log::channel('data')->debug('GC Transaction Details', $transactions);

        // Generate balance events
        foreach ($accounts as $account) {
            $account = (array) $account;
            $date = Carbon::now()->toDateString();
            // Event UID
            $uid = $date . '_' . $account['name'];
            // Event Metadata
            $event_metadata = [];
            $event_metadata['date'] = $date;
            $event_metadata['spark'] = true;
            if ($account['cashAccountType'] == 'CACC') {
                $event_metadata['type'] = 'current_account';
                $object_concept = 'money_bank_account';
                $object_type = 'current_account';
            } elseif ($account['cashAccountType'] == 'CARD') {
                $event_metadata['type'] = 'credit_card_account';
                $object_concept = 'money_credit_account';
                $object_type = 'credit_card_account';
            } elseif ($account['cashAccountType'] == 'CHAR') {
                $event_metadata['type'] = 'credit_card_account';
                $object_concept = 'money_credit_account';
                $object_type = 'credit_card_account';
            } elseif ($account['cashAccountType'] == 'SVGS') {
                $event_metadata['type'] = 'savings_account';
                $object_concept = 'money_savings_account';
                $object_type = 'savings_account';
            } else {
                $event_metadata['type'] = 'current_account';
                $object_concept = 'money_bank_account';
                $object_type = 'current_account';
            }
            $event_metadata = array_merge($event_metadata, $account);
            // Event Array
            $event = [];
            $event['action'] = 'had_balance';
            $event['domain'] = 'money';
            $event['service'] = 'go_cardless';
            $event['time'] = new DateTime(Carbon::now());
            $event['value'] = abs($account['balance']);
            $event['value_unit'] = $account['balance_currency'];
            $event['metadata'] = (! $event_metadata ? null : $event_metadata);
            // Object Metadata
            $actor_metadata = $account;
            // Object Array
            $actor = [];
            $actor['uid'] = 'gc_account_' . $account['name'];
            $actor['concept'] = $object_concept;
            $actor['type'] = $object_type;
            $actor['title'] = $account['title'];
            $actor['content'] = null;
            $actor['metadata'] = (! $actor_metadata ? null : $actor_metadata);
            $actor['url'] = null;
            $actor['image_url'] = null;
            $actor['time'] = null;
            // Target Metadata
            $target_metadata = [];
            // Target Array
            $target = [];
            $target['uid'] = $date;
            $target['concept'] = 'day';
            $target['type'] = 'day';
            $target['title'] = $date;
            $target['content'] = null;
            $target['metadata'] = (! $target_metadata ? null : $target_metadata);
            $target['url'] = null;
            $target['image_url'] = null;
            $target['time'] = new DateTime(Carbon::createFromFormat('Y-m-d', $date));
            // Convert to objects
            $event_object = (object) $event;
            $actor_object = (object) $actor;
            $target_object = (object) $target;
            // Add event
            DataModel::withExceptions()->update($event_object, $actor_object, $target_object, $uid);
        }
    }
}
