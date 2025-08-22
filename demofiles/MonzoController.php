<?php

namespace App\Http\Controllers;

use Amelia\Monzo\Client;
use Amelia\Monzo\Monzo;
use App\Jobs\InitialiseMonzoAccounts;
use App\Jobs\SyncMonzo;
use App\Models\User;
use CronxCo\DataModel\DataModelFacade as DataModel;
use CronxCo\DataModel\Objects;
use DateTime;
use GuzzleHttp\Client as Guzzle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MonzoController extends Controller
{
    public function index()
    {
        $client = new Client(
            new Guzzle,
            getenv('MONZO_CLIENT_ID') ?: null,
            getenv('MONZO_CLIENT_SECRET') ?: null
        );

        $monzo = new Monzo($client);
        $user = User::findOrFail(auth()->id());

        $accounts = $monzo->as($user)->accounts();

        // Get uk_retail account
        $collatedPots = [];
        $potArray = [];
        foreach ($accounts as $account) {
            if ($account['type'] == 'uk_retail') {
                $primaryAccount = $account['id'];
            }
            if ($account['type'] != 'uk_monzo_flex_backing_loan') {
                $balance_array = $monzo->as($user)->balance($account['id']);

                if ($balance_array['total_balance'] != 0) {
                }

                $pots = $monzo->as($user)->pots($account['id']);
                // echo("<pre>");
                // print_r($pots->toArray());
                // echo("</pre>");
                // echo("<hr/>");
                if (! empty($pots->toArray())) {
                    $collatedPots = array_merge($collatedPots, $pots->toArray());
                }

                foreach ($pots as $pot) {
                    $potArray[$pot['id']] = $pot['name'];
                }
            }
        }

        $since = now()->subDays(75)->startOfDay()->toIso8601String();

        // $account_objects = Objects::where('object_type','=','monzo_account')->orWhere('object_type','=','monzo_pot_account')->get();

        // foreach ($account_objects as $account_object) {
        //     echo("<pre>");
        //     print_r($account_object);
        //     echo("</pre>");
        //     echo("<hr/>");
        //     echo("<pre>");
        //     print_r($account_object->object_uid);
        //     echo("</pre>");
        //     echo("<hr/>");
        // }

        echo '<pre>';
        print_r($potArray);
        echo '</pre>';
        echo '<hr/>';
        echo '<pre>';
        print_r($accounts);
        echo '</pre>';
        $pot_accounts = [];
        foreach ($accounts as $account) {
            echo $account['id'] . ' ' . $account['type'] . '<br />';
            if ($account['type'] != 'uk_monzo_flex_backing_loan') {
                $transactions = $monzo->as($user)->expand('account')->since($since)->transactions($account['id']);
                echo '<hr/><pre>';
                print_r($transactions);
                echo '</pre>';

                foreach ($transactions as $transaction) {
                    if (! empty($transaction['metadata']['pot_account_id'])) {
                        $pot_account_details = [
                            'pot_account_id' => $transaction['metadata']['pot_account_id'],
                            'pot_id' => $transaction['metadata']['pot_id'],
                        ];
                        array_push($pot_accounts, $pot_account_details);
                    }
                }
            }
            $pot_accounts = $this->uniqueMultidimArray($pot_accounts, 'pot_account_id');
            foreach ($pot_accounts as $pot_account) {
                print_r($pot_account);
            }
        }
    }

    public function uniqueMultidimArray($array, $key)
    {
        $temp_array = [];
        $i = 0;
        $key_array = [];

        foreach ($array as $val) {
            if (! in_array($val[$key], $key_array)) {
                $key_array[$i] = $val[$key];
                $temp_array[$i] = $val;
            }
            $i++;
        }

        return $temp_array;
    }

    public function processTransactions($transactions, $potArray, $accounts = null, $pots = null)
    {
        if (isset($accounts)) {
            foreach ($accounts as $account) {
                // Log::debug('Monzo Account:'. $account['type']);
                // Object Metadata
                $object_metadata = $account;
                // Object Array
                $object = [];
                $object['uid'] = $account['id'];
                if ($account['type'] == 'uk_retail') {
                    $object['title'] = 'Current Account';
                    $object['concept'] = 'money_bank_account';
                } elseif ($account['type'] == 'uk_prepaid') {
                    $object['title'] = 'Prepaid Account';
                    $object['concept'] = 'money_bank_account';
                } elseif ($account['type'] == 'uk_retail_joint') {
                    $object['title'] = 'Joint Account';
                    $object['concept'] = 'money_bank_account';
                    $object_metadata['joint'] = true;
                    $joint_account_id = $account['id'];
                } elseif ($account['type'] == 'uk_monzo_flex') {
                    $object['title'] = 'Monzo Flex';
                    $object['concept'] = 'money_credit_account';
                } elseif ($account['type'] == 'uk_monzo_flex_backing_loan') {
                    $object['title'] = 'Flex Backing Loan';
                    $object['concept'] = 'money_credit_account';
                } elseif ($account['type'] == 'uk_rewards') {
                    $object['title'] = 'Rewards';
                    $object['concept'] = 'money_bank_account';
                } else {
                    $object['title'] = 'Other Monzo Account';
                    $object['concept'] = 'money_bank_account';
                }
                if (! empty($account['closed'])) {
                    $object['concept'] = 'money_closed_account';
                    $object['title'] = 'Closed Monzo Account';
                    $object['type'] = 'closed_monzo_account';
                } else {
                    $object['type'] = 'monzo_account';
                }
                $object['content'] = null;
                $object['metadata'] = (! $object_metadata ? null : $object_metadata);
                $object['url'] = null;
                $object['image_url'] = null;
                $object['time'] = new DateTime($account['created']);
                // Convert to object
                $object_object = (object) $object;

                $DataModel = DataModel::withExceptions()->updateObject($object_object);
            }
        }

        if (isset($pots)) {
            foreach ($pots as $pot) {
                // Object Metadata
                $object_metadata = $pot;
                if ($pot['current_account_id'] == $joint_account_id) {
                    $object_metadata['joint'] = true;
                }
                // Object Array
                $object = [];
                $object['uid'] = $pot['id'];
                if (! empty($pot['deleted'])) {
                    $object['concept'] = 'money_closed_account';
                    $object['type'] = 'closed_monzo_pot';
                } else {
                    $object['concept'] = 'money_savings_account';
                    $object['type'] = 'monzo_pot';
                }
                $object['title'] = $pot['name'];
                $object['content'] = $pot['balance'];
                $object['metadata'] = (! $object_metadata ? null : $object_metadata);
                $object['url'] = null;
                $object['image_url'] = null;
                $object['time'] = new DateTime($pot['created']);
                // Convert to object
                $object_object = (object) $object;
                // Add event
                $DataModel = DataModel::withExceptions()->updateObject($object_object);
            }
        }

        // Create the pot accounts array

        $pot_accounts = [];

        foreach ($transactions as $transaction) {

            // Set object details
            if (isset($transaction['merchant']['name'])) {
                $target_name = $transaction['merchant']['name'];
                $target_uid = $transaction['merchant']['id'];
                $target_type = 'merchant';
            } elseif ($transaction['scheme'] == 'uk_retail_pot') {
                $target_name = $potArray[$transaction['description']];
                $target_uid = $transaction['description'];
                $target_type = 'monzo_pot';
            } elseif (isset($transaction['counterparty']['account_id']) && isset($transaction['counterparty']['name'])) {
                $target_name = $transaction['counterparty']['name'];
                $target_uid = $transaction['counterparty']['account_id'];
                $target_type = 'monzo_recipient';
            } elseif (isset($transaction['counterparty']['account_id']) && isset($transaction['counterparty']['name'])) {
                $target_name = $transaction['counterparty']['account_id'];
                $target_uid = $transaction['counterparty']['account_id'];
                $target_type = 'monzo_recipient';
            } elseif (isset($transaction['counterparty']['number'])) {
                $target_name = $transaction['counterparty']['number'];
                $target_uid = $transaction['counterparty']['user_id'];
                $target_type = 'monzo_recipient';
            } elseif (isset($transaction['counterparty']['sort_code'])) {
                $target_name = $transaction['counterparty']['name'];
                $target_uid = $transaction['counterparty']['sort_code'] . '-' . $transaction['counterparty']['account_number'];
                $target_type = 'bank_account';
            } elseif (isset($transaction['counterparty']['name'])) {
                $target_name = $transaction['counterparty']['name'];
                $target_uid = $transaction['counterparty']['user_id'];
                $target_type = 'monzo_recipient';
            } else {
                $target_name = $transaction['description'];
                $target_uid = 'monzo_' . Str::snake($transaction['description']);
                $target_type = 'monzo_recipient';
            }

            // Set actions
            if ($transaction['scheme'] == 'mastercard') {
                if ($transaction['declined'] == 1) {
                    $action = 'declined_payment_to';
                } elseif ($transaction['amount'] < 0) {
                    $action = 'card_payment_to';
                } else {
                    $action = 'card_refund_from';
                }
            } elseif ($transaction['scheme'] == 'uk_retail_pot') {
                if ($transaction['amount'] < 0) {
                    $action = 'pot_transfer_to';
                } else {
                    $action = 'pot_withdrawal_from';
                }
            } elseif ($transaction['scheme'] == 'account_interest') {
                if ($transaction['amount'] < 0) {
                    $action = 'interest_repaid';
                } else {
                    $action = 'interest_earned';
                }
            } elseif ($transaction['scheme'] == 'monzo_flex') {
                if ($transaction['amount'] < 0) {
                    $action = 'monzo_flex_payment';
                } else {
                    $action = 'monzo_flex_loan';
                }
            } elseif ($transaction['scheme'] == 'bacs') {
                if ($transaction['amount'] > 150000 && $transaction['merchant']['name'] == config('services.monzo.salary_name')) {
                    $action = 'salary_received_from';
                } elseif ($transaction['amount'] < 0) {
                    $action = 'direct_debit_to';
                } else {
                    $action = 'direct_credit_from';
                }
            } elseif ($transaction['scheme'] == 'p2p_payment') {
                if ($transaction['amount'] < 0) {
                    $action = 'monzo_me_to';
                } else {
                    $action = 'monzo_me_from';
                }
            } elseif ($transaction['scheme'] == 'payport_faster_payments') {
                if ($transaction['amount'] < 0) {
                    $action = 'bank_transfer_to';
                } else {
                    $action = 'bank_transfer_from';
                }
            } elseif ($transaction['scheme'] == 'monzo_paid') {
                if ($transaction['amount'] < 0) {
                    $action = 'fee_paid_for';
                } else {
                    $action = 'fee_refunded_for';
                }
            } else {
                if ($transaction['amount'] < 0) {
                    $action = 'other_debit_to';
                } else {
                    $action = 'other_credit_from';
                }
            }

            // Set event data

            // Event UID
            $uid = $transaction['id'];
            // Event Metadata
            $additional_metadata = [];
            $additional_metadata['local_amount'] = $transaction['local_amount'];
            $additional_metadata['local_currency'] = $transaction['local_currency'];
            $event_metadata = array_merge($transaction['metadata'], $additional_metadata);
            // Event Array
            $event = [];
            $event['action'] = $action;
            $event['domain'] = 'money';
            $event['service'] = 'monzo';
            $event['time'] = new DateTime($transaction['created']);
            $event['value'] = abs($transaction['amount'] / 100);
            $event['value_unit'] = 'GBP';
            $event['metadata'] = (! $event_metadata ? null : $event_metadata);
            // Actor Metadata
            $actor_metadata = [];
            if (isset($transaction['virtual_card'])) {
                $actor_metadata = array_merge($actor_metadata, $transaction['virtual_card']);
            }
            // Actor Array
            $actor = [];
            $actor['uid'] = $transaction['account_id']; // We've already created object records for all accounts and pots
            $actor['concept'] = null;
            $actor['type'] = null;
            $actor['title'] = null;
            $actor['content'] = null;
            $actor['metadata'] = (! $actor_metadata ? null : $actor_metadata);
            $actor['url'] = null;
            $actor['image_url'] = null;
            $actor['time'] = null;
            // Object Metadata
            $target_metadata = [];
            if (isset($transaction['merchant'])) {
                $merchant_data = (array) json_decode(str_replace('\u0000*\u0000', '', json_encode((array) $transaction['merchant'])));
                if (isset($merchant_data['attributes'])) {
                    $target_metadata = array_merge($target_metadata, (array) $merchant_data['attributes']);
                }
            }
            if (isset($transaction['counterparty'])) {
                $target_metadata = array_merge($target_metadata, $transaction['counterparty']);
            }

            // Define tags
            $tags = [];
            if (isset($transaction['category'])) {
                $tags[$transaction['category']] = [
                    'name' => $transaction['category'],
                    'category' => 'monzo-category',
                ];
            }

            if ($transaction['amount'] < 0) {
                $tags['debit'] = [
                    'name' => 'debit',
                    'category' => 'transaction-type',
                ];
            } elseif ($transaction['amount'] > 0) {
                $tags['credit'] = [
                    'name' => 'credit',
                    'category' => 'transaction-type',
                ];
            }

            if (isset($transaction['scheme'])) {
                $tags[$transaction['scheme']] = [
                    'name' => $transaction['scheme'],
                    'category' => 'payment-scheme',
                ];
            }

            if (isset($transaction['local_currency'])) {
                $tags[$transaction['local_currency']] = [
                    'name' => $transaction['local_currency'],
                    'category' => 'currency',
                ];
            }

            if (! empty($transaction['merchant']['emoji'])) {
                $tags[$transaction['merchant']['emoji']] = [
                    'name' => $transaction['merchant']['emoji'],
                    'category' => 'monzo-emoji',
                ];
            }

            if (! empty($transaction['merchant']['address']['country'])) {
                $tags[$transaction['merchant']['address']['country']] = [
                    'name' => $transaction['merchant']['address']['country'],
                    'category' => 'country',
                ];
            }

            if (! empty($transaction['merchant']['category'])) {
                $tags[$transaction['merchant']['category']] = [
                    'name' => $transaction['merchant']['category'],
                    'category' => 'monzo-category',
                ];
            }

            if ($transaction['declined'] == 1) {
                $tags['declined'] = [
                    'name' => 'declined',
                    'category' => 'monzo-status',
                ];
                $tags[$transaction['decline_reason']] = [
                    'name' => $transaction['decline_reason'],
                    'category' => 'monzo-decline-reason',
                ];
            } elseif ($transaction['pending'] != 1) {
                $tags['settled'] = [
                    'name' => 'settled',
                    'category' => 'monzo-status',
                ];
            }

            // Object Array
            $target = [];
            $target['uid'] = $target_uid;
            $target['concept'] = 'b_party';
            $target['type'] = $target_type;
            $target['title'] = $target_name;
            $target['content'] = null;
            $target['metadata'] = (! $target_metadata ? null : $target_metadata);
            $target['url'] = null;
            $target['image_url'] = null;
            $target['time'] = null;
            // Convert to objects
            $event_object = (object) $event;
            $actor_object = (object) $actor;
            $target_object = (object) $target;
            // Add event
            $DataModel = DataModel::withExceptions()->update($event_object, $actor_object, $target_object, $uid, $tags);

            // Generate Pot Account list

            if (! empty($transaction['metadata']['pot_account_id'])) {
                $pot_account_details = [
                    'pot_account_id' => $transaction['metadata']['pot_account_id'],
                    'pot_id' => $transaction['metadata']['pot_id'],
                ];
                array_push($pot_accounts, $pot_account_details);
            }
        }
        $pot_accounts = $this->uniqueMultidimArray($pot_accounts, 'pot_account_id');
        foreach ($pot_accounts as $pot_account) {
            // Object Metadata
            $object_metadata = [];
            // Object Array
            $object = [];
            $object['uid'] = $pot_account['pot_account_id'];
            $object['concept'] = 'money_enabling_account';
            $object['type'] = 'monzo_pot_account';
            $object['title'] = $pot_account['pot_id'];
            $object['content'] = null;
            $object['metadata'] = (! $object_metadata ? null : $object_metadata);
            $object['url'] = null;
            $object['image_url'] = null;
            $object['time'] = null;
            // Convert to object
            $object_object = (object) $object;
            // Add event
            $DataModel = DataModel::withExceptions()->addObject($object_object);
        }
    }

    public function processPotTransactions($transactions, $potArray, $account_object)
    {
        foreach ($transactions as $transaction) {

            // Set object details
            if (isset($transaction['merchant']['name'])) {
                $target_name = $transaction['merchant']['name'];
                $target_uid = $transaction['merchant']['id'];
                $target_type = 'merchant';
            } elseif ($transaction['scheme'] == 'uk_retail_pot') {
                $target_name = null;
                if (! empty($transaction['metadata']['source_account_id'])) {
                    $target_uid = $transaction['metadata']['source_account_id'];
                } elseif (! empty($transaction['metadata']['destination_account_id'])) {
                    $target_uid = $transaction['metadata']['destination_account_id'];
                }
                $target_type = 'monzo_account';
            } elseif (isset($transaction['counterparty']['account_id'])) {
                $target_name = $transaction['counterparty']['name'];
                $target_uid = $transaction['counterparty']['account_id'];
                $target_type = 'monzo_recipient';
            } elseif (isset($transaction['counterparty']['name'])) {
                $target_name = $transaction['counterparty']['name'];
                $target_uid = $transaction['counterparty']['sort_code'] . '-' . $transaction['counterparty']['account_number'];
                $target_type = 'bank_account';
            } else {
                $target_name = $transaction['description'];
                $target_uid = 'monzo_' . Str::snake($transaction['description']);
                $target_type = 'monzo_recipient';
            }

            // Set actions
            // Declaring the additional metadata array early on this function (so we can hide certain, but not all, pot transactions)
            $additional_metadata = [];
            if ($transaction['scheme'] == 'mastercard') {
                if ($transaction['declined'] == 1) {
                    $action = 'declined_payment_to';
                } elseif ($transaction['amount'] < 0) {
                    $action = 'card_payment_to';
                } else {
                    $action = 'card_refund_from';
                }
            } elseif ($transaction['scheme'] == 'uk_retail_pot') {
                if ($transaction['amount'] < 0) {
                    $action = 'pot_debit_to';
                    $additional_metadata['spark'] = true;
                } else {
                    $action = 'pot_credit_from';
                    $additional_metadata['spark'] = true;
                }
            } elseif ($transaction['scheme'] == 'account_interest') {
                if ($transaction['amount'] < 0) {
                    $action = 'interest_repaid';
                } else {
                    $action = 'interest_earned';
                }
            } elseif ($transaction['scheme'] == 'monzo_flex') {
                if ($transaction['amount'] < 0) {
                    $action = 'monzo_flex_payment';
                } else {
                    $action = 'monzo_flex_loan';
                }
            } elseif ($transaction['scheme'] == 'bacs') {
                if ($transaction['amount'] > 150000 && $transaction['merchant']['name'] == config('services.monzo.salary_name')) {
                    $action = 'salary_received_from';
                } elseif ($transaction['amount'] < 0) {
                    $action = 'direct_debit_to';
                } else {
                    $action = 'direct_credit_from';
                }
            } elseif ($transaction['scheme'] == 'p2p_payment') {
                if ($transaction['amount'] < 0) {
                    $action = 'monzo_me_to';
                } else {
                    $action = 'monzo_me_from';
                }
            } elseif ($transaction['scheme'] == 'payport_faster_payments') {
                if ($transaction['amount'] < 0) {
                    $action = 'bank_transfer_to';
                } else {
                    $action = 'bank_transfer_from';
                }
            } elseif ($transaction['scheme'] == 'monzo_paid') {
                if ($transaction['amount'] < 0) {
                    $action = 'fee_paid_for';
                } else {
                    $action = 'fee_refunded_for';
                }
            } else {
                if ($transaction['amount'] < 0) {
                    $action = 'other_debit_to';
                } else {
                    $action = 'other_credit_from';
                }
            }

            // Define tags
            $tags = [];
            if ($transaction['scheme'] != 'uk_retail_pot') {
                if (isset($transaction['category'])) {
                    $tags[$transaction['category']] = [
                        'name' => $transaction['category'],
                        'category' => 'monzo-category',
                    ];
                }

                if ($transaction['amount'] < 0) {
                    $tags['debit'] = [
                        'name' => 'debit',
                        'category' => 'transaction-type',
                    ];
                } elseif ($transaction['amount'] > 0) {
                    $tags['credit'] = [
                        'name' => 'credit',
                        'category' => 'transaction-type',
                    ];
                }

                if (isset($transaction['scheme'])) {
                    $tags[$transaction['scheme']] = [
                        'name' => $transaction['scheme'],
                        'category' => 'payment-scheme',
                    ];
                }

                if (isset($transaction['local_currency'])) {
                    $tags[$transaction['local_currency']] = [
                        'name' => $transaction['local_currency'],
                        'category' => 'currency',
                    ];
                }

                if (! empty($transaction['merchant']['emoji'])) {
                    $tags[$transaction['merchant']['emoji']] = [
                        'name' => $transaction['merchant']['emoji'],
                        'category' => 'monzo-emoji',
                    ];
                }

                if (! empty($transaction['merchant']['address']['country'])) {
                    $tags[$transaction['merchant']['address']['country']] = [
                        'name' => $transaction['merchant']['address']['country'],
                        'category' => 'country',
                    ];
                }

                if (! empty($transaction['merchant']['category'])) {
                    $tags[$transaction['merchant']['category']] = [
                        'name' => $transaction['merchant']['category'],
                        'category' => 'monzo-category',
                    ];
                }

                if ($transaction['declined'] == 1) {
                    $tags['declined'] = [
                        'name' => 'declined',
                        'category' => 'monzo-status',
                    ];
                    $tags[$transaction['decline_reason']] = [
                        'name' => $transaction['decline_reason'],
                        'category' => 'monzo-decline-reason',
                    ];
                } elseif ($transaction['pending'] != 1) {
                    $tags['settled'] = [
                        'name' => 'settled',
                        'category' => 'monzo-status',
                    ];
                }
            }

            // Set event data

            // Event UID
            $uid = $transaction['id'];
            // Event Metadata
            $additional_metadata['local_amount'] = $transaction['local_amount'];
            $additional_metadata['local_currency'] = $transaction['local_currency'];
            $event_metadata = array_merge($transaction['metadata'], $additional_metadata);
            // Event Array
            $event = [];
            $event['action'] = $action;
            $event['domain'] = 'money';
            $event['service'] = 'monzo';
            $event['time'] = new DateTime($transaction['created']);
            $event['value'] = abs($transaction['amount'] / 100);
            $event['value_unit'] = 'GBP';
            $event['metadata'] = (! $event_metadata ? null : $event_metadata);
            // Actor Metadata
            $actor_metadata = [];
            if (isset($transaction['virtual_card'])) {
                $actor_metadata = array_merge($actor_metadata, $transaction['virtual_card']);
            }
            $actor_metadata['pot_account_id'] = $account_object['object_uid'];
            // Actor Array
            $actor = [];
            $actor['uid'] = $account_object['object_title']; // We've already created object records for all accounts and pots
            $actor['concept'] = null;
            $actor['type'] = null;
            $actor['title'] = null;
            $actor['content'] = null;
            $actor['metadata'] = (! $actor_metadata ? null : $actor_metadata);
            $actor['url'] = null;
            $actor['image_url'] = null;
            $actor['time'] = null;
            // Object Metadata
            $target_metadata = [];
            if (isset($transaction['merchant'])) {
                $merchant_data = (array) json_decode(str_replace('\u0000*\u0000', '', json_encode((array) $transaction['merchant'])));
                if (isset($merchant_data['attributes'])) {
                    $target_metadata = array_merge($target_metadata, (array) $merchant_data['attributes']);
                }
            }
            if (isset($transaction['counterparty'])) {
                $target_metadata = array_merge($target_metadata, $transaction['counterparty']);
            }

            // Object Array
            $target = [];
            $target['uid'] = $target_uid;
            $target['concept'] = 'b_party';
            $target['type'] = $target_type;
            $target['title'] = $target_name;
            $target['content'] = null;
            $target['metadata'] = (! $target_metadata ? null : $target_metadata);
            $target['url'] = null;
            $target['image_url'] = null;
            $target['time'] = null;
            // Convert to objects
            $event_object = (object) $event;
            $actor_object = (object) $actor;
            $target_object = (object) $target;
            // Add event
            $DataModel = DataModel::withExceptions()->update($event_object, $actor_object, $target_object, $uid, $tags);
        }
    }

    public function processAccounts($transactions, $accounts, $pots)
    {
        foreach ($accounts as $account) {
            // Object Metadata
            $object_metadata = $account;
            // Object Array
            $object = [];
            $object['uid'] = $account['id'];
            if ($account['type'] == 'uk_retail') {
                $object['title'] = 'Current Account';
                $object['concept'] = 'money_bank_account';
            } elseif ($account['type'] == 'uk_prepaid') {
                $object['title'] = 'Prepaid Account';
                $object['concept'] = 'money_bank_account';
            } elseif ($account['type'] == 'uk_retail_joint') {
                $object['title'] = 'Joint Account';
                $object['concept'] = 'money_bank_account';
                $object_metadata['joint'] = true;
                $joint_account_id = $account['id'];
            } elseif ($account['type'] == 'uk_monzo_flex') {
                $object['title'] = 'Monzo Flex';
                $object['concept'] = 'money_credit_account';
            } elseif ($account['type'] == 'uk_monzo_flex_backing_loan') {
                $object['title'] = 'Flex Backing Loan';
                $object['concept'] = 'money_credit_account';
            } elseif ($account['type'] == 'uk_rewards') {
                $object['title'] = 'Rewards';
                $object['concept'] = 'money_bank_account';
            } else {
                $object['title'] = 'Other Monzo Account';
                $object['concept'] = 'money_bank_account';
            }
            if (! empty($account['closed'])) {
                $object['concept'] = 'money_closed_account';
                $object['title'] = 'Closed Monzo Account';
                $object['type'] = 'closed_monzo_account';
            } else {
                $object['type'] = 'monzo_account';
            }
            $object['content'] = null;
            $object['metadata'] = (! $object_metadata ? null : $object_metadata);
            $object['url'] = null;
            $object['image_url'] = null;
            $object['time'] = new DateTime($account['created']);
            // Convert to object
            $object_object = (object) $object;
            // Add event
            $DataModel = DataModel::withExceptions()->updateObject($object_object);
        }

        foreach ($pots as $pot) {
            // Object Metadata
            $object_metadata = $pot;
            if ($pot['current_account_id'] == $joint_account_id) {
                $object_metadata['joint'] = true;
            }
            // Object Array
            $object = [];
            $object['uid'] = $pot['id'];
            if (! empty($pot['deleted'])) {
                $object['concept'] = 'money_closed_account';
                $object['type'] = 'closed_monzo_pot';
            } else {
                $object['concept'] = 'money__savings_account';
                $object['type'] = 'monzo_pot';
            }
            $object['title'] = $pot['name'];
            $object['content'] = $pot['balance'];
            $object['metadata'] = (! $object_metadata ? null : $object_metadata);
            $object['url'] = null;
            $object['image_url'] = null;
            $object['time'] = new DateTime($pot['created']);
            // Convert to object
            $object_object = (object) $object;
            // Add event
            $DataModel = DataModel::withExceptions()->updateObject($object_object);
        }

        // Create the pot accounts array

        $pot_accounts = [];

        foreach ($transactions as $transaction) {

            // Generate Pot Account list

            if (! empty($transaction['metadata']['pot_account_id'])) {
                $pot_account_details = [
                    'pot_account_id' => $transaction['metadata']['pot_account_id'],
                    'pot_id' => $transaction['metadata']['pot_id'],
                ];
                array_push($pot_accounts, $pot_account_details);
            }
        }

        // Dedupe the pot_accounts array
        $pot_accounts = $this->uniqueMultidimArray($pot_accounts, 'pot_account_id');

        foreach ($pot_accounts as $pot_account) {
            // Object Metadata
            $object_metadata = [];
            // Object Array
            $object = [];
            $object['uid'] = $pot_account['pot_account_id'];
            $object['concept'] = 'money_enabling_account';
            $object['type'] = 'monzo_pot_account';
            $object['title'] = $pot_account['pot_id'];
            $object['content'] = null;
            $object['metadata'] = (! $object_metadata ? null : $object_metadata);
            $object['url'] = null;
            $object['image_url'] = null;
            $object['time'] = null;
            // Convert to object
            $object_object = (object) $object;
            // Add event
            $DataModel = DataModel::withExceptions()->updateObject($object_object);
        }
    }

    public function updateBalance($account_id, $balance, $account_name, $type)
    {
        // Invert flex balance
        if ($account_name == 'uk_monzo_flex') {
            $balance = -$balance;
        }
        $date = Carbon::now()->toDateString();
        $uid = $date . '_' . $account_id;
        // Event UID
        $uid = $uid;
        // Event Metadata
        $event_metadata = [];
        $event_metadata['date'] = $date;
        $event_metadata['spark'] = true;
        $event_metadata['name'] = $account_name;
        $event_metadata['type'] = $type;
        // Event Array
        $event = [];
        $event['action'] = 'had_balance';
        $event['domain'] = 'money';
        $event['service'] = 'monzo';
        $event['time'] = new DateTime(Carbon::now());
        $event['value'] = $balance / 100;
        $event['value_unit'] = 'GBP';
        $event['metadata'] = (! $event_metadata ? null : $event_metadata);
        // Actor Metadata
        $actor_metadata = [];
        // Actor Array
        $actor = [];
        $actor['uid'] = $account_id; // We've already created object records for all accounts and pots
        $actor['concept'] = null;
        $actor['type'] = null;
        $actor['title'] = null;
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
        $DataModel = DataModel::withExceptions()->update($event_object, $actor_object, $target_object, $uid);
    }

    public function spentToday($account_id, $spent_today)
    {
        $date = Carbon::now()->toDateString();
        $uid = $date . '_' . $account_id;
        // Event UID
        $uid = $uid;
        // Event Metadata
        $event_metadata = [];
        $event_metadata['date'] = $date;
        $event_metadata['spark'] = true;
        // Event Array
        $event = [];
        $event['action'] = 'spent_today';
        $event['domain'] = 'money';
        $event['service'] = 'monzo';
        $event['time'] = new DateTime(Carbon::now());
        $event['value'] = $spent_today / 100;
        $event['value_unit'] = 'GBP';
        $event['metadata'] = (! $event_metadata ? null : $event_metadata);
        // Actor Metadata
        $actor_metadata = [];
        // Actor Array
        $actor = [];
        $actor['uid'] = $account_id; // We've already created object records for all accounts and pots
        $actor['concept'] = null;
        $actor['type'] = null;
        $actor['title'] = null;
        $actor['content'] = null;
        $actor['metadata'] = (! $actor_metadata ? null : $actor_metadata);
        $actor['url'] = null;
        $actor['image_url'] = null;
        $actor['time'] = null;
        // Object Metadata
        $target_metadata = [];
        // Object Array
        $target = [];
        $target['uid'] = $account_id;
        $target['concept'] = null;
        $target['type'] = null;
        $target['title'] = null;
        $target['content'] = null;
        $target['metadata'] = (! $target_metadata ? null : $target_metadata);
        $target['url'] = null;
        $target['image_url'] = null;
        $target['time'] = null;
        // Convert to objects
        $event_object = (object) $event;
        $actor_object = (object) $actor;
        $target_object = (object) $target;
        // Add event
        $DataModel = DataModel::withExceptions()->update($event_object, $actor_object, $target_object, $uid);
    }

    public function transactions()
    {
        $client = new Client(
            new Guzzle,
            getenv('MONZO_CLIENT_ID') ?: null,
            getenv('MONZO_CLIENT_SECRET') ?: null
        );

        $monzo = new Monzo($client);
        $user = User::findOrFail(auth()->id());

        $accounts = $monzo->as($user)->accounts();

        // // Get uk_retail account
        // foreach ($accounts as $account) {
        //     if ($account['type'] == "uk_retail"){
        //         $primaryAccount = $account['id'];
        //     }
        // }

        $since = now()->subDays(89)->startOfDay()->toIso8601String();

        // $transactions = $monzo->as($user)->since($since)->transactions($primaryAccount);

        // $pots = $monzo->as($user)->pots($primaryAccount);
        // $potArray = [];
        // foreach ($pots as $pot) {
        //     $potArray[$pot['id']] = $pot['name'];
        // }

        // $this->processTransactions($transactions, $potArray, $accounts);

        foreach ($accounts as $account) {
            if ($account['type'] != 'uk_monzo_flex_backing_loan') {
                $pots = $monzo->as($user)->pots($account['id']);
                $potArray = [];
                foreach ($pots as $pot) {
                    $potArray[$pot['id']] = $pot['name'];
                }

                // $transactions = $monzo->as($user)->expand('account')->since($since)->transactions($account['id']);
                // $this->processTransactions($transactions, $potArray, $accounts);
            }
        }

        $transactions = $monzo->as($user)->expand('account')->since($since)->transactions('acc_00009mQItudc0zLBzOY93J');

        echo '<hr/><pre>';
        print_r($transactions);
        echo '</pre>';
    }

    public function sync(): RedirectResponse
    {
        SyncMonzo::dispatch();

        return redirect('/today');
    }

    public function initialise(): RedirectResponse
    {
        InitialiseMonzoAccounts::dispatch();

        return redirect('/today');
    }

    public function pots()
    {
        $client = new Client(
            new Guzzle,
            getenv('MONZO_CLIENT_ID') ?: null,
            getenv('MONZO_CLIENT_SECRET') ?: null
        );

        $monzo = new Monzo($client);
        $user = User::findOrFail(auth()->id());

        // Get uk_retail account
        $accounts = $monzo->as($user)->accounts();
        foreach ($accounts as $account) {
            if ($account['type'] == 'uk_retail') {
                $primaryAccount = $account['id'];
            }
        }

        $pots = $monzo->as($user)->pots($primaryAccount);

        echo '<hr/><pre>';
        print_r($pots);
        echo '</pre>';

        foreach ($pots as $pot) {

            // Pot creation events

            $source_uid = $pot['id'];
            $event = [];
            $event['action'] = 'created_pot';
            $event['domain'] = 'money';
            $event['service'] = 'monzo';
            $event['time'] = new DateTime($pot['created']);
            $event_object = (object) $event;
            DataModel::withExceptions()->add($event_object, $pot['name'], $pot['current_account_id'], $source_uid);

            // Pot deletion events

            if ($pot['deleted'] == 1) {
                $source_uid = $pot['id'];
                $event = [];
                $event['action'] = 'deleted_pot';
                $event['domain'] = 'money';
                $event['service'] = 'monzo';
                $event['time'] = new DateTime($pot['updated']);
                $event_object = (object) $event;
                DataModel::withExceptions()->add($event_object, $pot['name'], $pot['current_account_id'], $source_uid);
            }
        }
    }
}
