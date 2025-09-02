<?php

namespace App\Jobs\Data\GoCardless;

use App\Jobs\Base\BaseProcessingJob;
use App\Models\Event;
use App\Models\EventObject;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class GoCardlessTransactionData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'gocardless';
    }

    protected function getJobType(): string
    {
        return 'transactions';
    }

    protected function process(): void
    {
        $transactionData = $this->rawData;

        // Extract transactions from the API response
        $bookedTransactions = $transactionData['transactions']['booked'] ?? [];
        $pendingTransactions = $transactionData['transactions']['pending'] ?? [];

        // Process pending transactions first
        foreach ($pendingTransactions as $transaction) {
            $this->processTransactionItem($transaction, 'pending');
        }

        // Process booked transactions (these may update existing pending transactions)
        foreach ($bookedTransactions as $transaction) {
            $this->processTransactionItem($transaction, 'booked');
        }
    }

    /**
     * Process a single transaction item with full GoCardless logic
     */
    protected function processTransactionItem(array $tx, string $status): void
    {
        // Derive category from transaction code
        $category = 'other';
        if (isset($tx['bankTransactionCode'])) {
            $category = Str::slug($tx['bankTransactionCode']);
        } elseif (isset($tx['proprietaryBankTransactionCode'])) {
            $category = Str::slug($tx['proprietaryBankTransactionCode']);
        }

        // Create consistent source ID based on transaction content
        $sourceId = $this->generateConsistentTransactionId($tx);

        // Check if this transaction already exists (for status transitions)
        $existingEvent = Event::where('integration_id', $this->integration->id)
            ->where('source_id', $sourceId)
            ->first();

        // Determine if this is a status change
        $isStatusChange = $existingEvent && $existingEvent->event_metadata['transaction_status'] !== $status;

        // Determine action based on transaction amount and direction
        $amount = (float) ($tx['transactionAmount']['amount'] ?? 0);
        $action = $this->determineTransactionAction($amount, $status);

        // Preserve the best available timestamp
        $timestamp = $this->determineBestTimestamp($tx, $existingEvent, $status, $isStatusChange);

        $eventData = [
            'user_id' => $this->integration->user_id,
            'action' => $action,
            'domain' => 'money',
            'service' => 'gocardless',
            'time' => $timestamp,
            'value' => abs($amount),
            'value_multiplier' => 100,
            'value_unit' => $tx['transactionAmount']['currency'] ?? 'GBP',
            'event_metadata' => [
                'category' => $category,
                'description' => $tx['remittanceInformationUnstructured'] ?? '',
                'bank_transaction_code' => $tx['bankTransactionCode'] ?? null,
                'proprietary_bank_transaction_code' => $tx['proprietaryBankTransactionCode'] ?? null,
                'booking_date' => $tx['bookingDate'] ?? null,
                'value_date' => $tx['valueDate'] ?? null,
                'check_id' => $tx['checkId'] ?? null,
                'creditor_id' => $tx['creditorId'] ?? null,
                'mandate_id' => $tx['mandateId'] ?? null,
                'creditor_account' => $tx['creditorAccount'] ?? null,
                'debtor_account' => $tx['debtorAccount'] ?? null,
                'transaction_status' => $status, // Track pending vs booked
                'status_changed' => $isStatusChange, // Track if this is a status transition
                'previous_status' => $existingEvent?->event_metadata['transaction_status'] ?? null,
                'timestamp_preserved' => $isStatusChange && $existingEvent && $existingEvent->time === $timestamp,
                'timestamp_reason' => $this->getTimestampReason($tx, $existingEvent, $status, $isStatusChange, $timestamp),
            ],
        ];

        $event = Event::updateOrCreate(
            [
                'integration_id' => $this->integration->id,
                'source_id' => $sourceId,
            ],
            $eventData
        );

        // Create or update actor (bank account)
        $accountObject = $this->upsertAccountObject($tx);

        // Create or update target (counterparty)
        $counterpartyObject = $this->upsertCounterpartyObject($tx);

        // Create the event relationship
        $event->objects()->syncWithoutDetaching([
            $accountObject->id => ['role' => 'actor'],
            $counterpartyObject->id => ['role' => 'target'],
        ]);

        // Add relevant tags based on transaction status
        $this->addTransactionTags($event, $tx, $status, $category, $isStatusChange, $existingEvent);

        // Add transaction blocks for additional data
        $this->addTransactionBlocks($event, $tx);

        // Add additional metadata blocks
        $this->addAdditionalBlocks($event, $tx, $status);
    }

    /**
     * Generate a consistent transaction ID that's the same for pending and booked versions
     */
    protected function generateConsistentTransactionId(array $transaction): string
    {
        // Get transaction date
        $date = $transaction['bookingDate'] ?? $transaction['valueDate'] ?? now()->toDateString();

        // Get counterparty name
        $counterparty = $transaction['creditorName'] ??
                       $transaction['debtorName'] ??
                       $transaction['remittanceInformationUnstructured'] ??
                       'unknown';

        // Get transaction amount (absolute value for consistency)
        $amount = abs((float) ($transaction['transactionAmount']['amount'] ?? 0));

        // Create content-based hash that's consistent between pending and booked states
        $contentString = $date . '_' .
                        Str::headline(Str::lower($counterparty)) . '_' .
                        $amount . '_' .
                        ($transaction['transactionAmount']['currency'] ?? 'GBP');

        return 'gc_' . md5($contentString);
    }

    /**
     * Determine the appropriate transaction action based on amount direction
     */
    protected function determineTransactionAction(float $amount, string $status): string
    {
        // Use directional actions for both pending and booked transactions
        if ($amount < 0) {
            return 'payment_to'; // Money going out
        } else {
            return 'payment_from'; // Money coming in
        }
    }

    /**
     * Determine the best timestamp to use, preserving precision from pending transactions
     */
    protected function determineBestTimestamp(array $currentTx, ?Event $existingEvent, string $status, bool $isStatusChange): string
    {
        $currentTimestamp = $currentTx['bookingDate'] ?? $currentTx['valueDate'] ?? now();

        // If no existing event, use current timestamp
        if (! $existingEvent) {
            return $currentTimestamp;
        }

        $existingTimestamp = $existingEvent->time;

        // If this is NOT a status change, keep existing timestamp to avoid unnecessary updates
        if (! $isStatusChange) {
            return $existingTimestamp;
        }

        // This is a status change (pending → booked), choose the most precise timestamp
        return $this->chooseBetterTimestamp($existingTimestamp, $currentTimestamp, $existingEvent, $status);
    }

    /**
     * Choose the better timestamp based on precision and context
     */
    protected function chooseBetterTimestamp(string $existingTime, string $newTime, Event $existingEvent, string $newStatus): string
    {
        $existingDateTime = Carbon::parse($existingTime);
        $newDateTime = Carbon::parse($newTime);

        // If existing transaction was pending and new is booked
        if ($existingEvent->event_metadata['transaction_status'] === 'pending' && $newStatus === 'booked') {

            // Check if the new timestamp looks like a generic batch processing time
            $newHour = $newDateTime->hour;
            $newMinute = $newDateTime->minute;

            $isGenericTime = (
                ($newHour >= 2 && $newHour <= 5 && $newMinute === 0) || // 2-5 AM with :00 minutes
                ($newHour === 0 && $newMinute === 0) || // Midnight
                ($newHour === 23 && $newMinute >= 55)   // Near midnight (end of day processing)
            );

            // If new time looks generic and existing time is more specific, keep existing
            if ($isGenericTime && ! $this->isGenericTime($existingDateTime)) {
                return $existingTime;
            }

            // If new time is more precise (has seconds/minutes that look real), use it
            if ($newMinute !== 0 || $newDateTime->second !== 0) {
                return $newTime;
            }

            // Default: keep the existing (pending) timestamp as it's likely more accurate
            return $existingTime;
        }

        // For other cases, use the newer timestamp
        return $newTime;
    }

    /**
     * Check if a timestamp looks like a generic/batch processing time
     */
    protected function isGenericTime(Carbon $dateTime): bool
    {
        $hour = $dateTime->hour;
        $minute = $dateTime->minute;

        return
            ($hour >= 2 && $hour <= 5 && $minute === 0) || // 2-5 AM with :00 minutes
            ($hour === 0 && $minute === 0) || // Midnight
            ($hour === 23 && $minute >= 55);   // Near midnight
    }

    /**
     * Get a human-readable explanation for timestamp choice
     */
    protected function getTimestampReason(array $currentTx, ?Event $existingEvent, string $status, bool $isStatusChange, string $chosenTimestamp): string
    {
        if (! $existingEvent) {
            return 'new_transaction';
        }

        if (! $isStatusChange) {
            return 'same_status_update';
        }

        $existingTime = $existingEvent->time;
        $currentTime = $currentTx['bookingDate'] ?? $currentTx['valueDate'] ?? now();

        if ($existingTime === $chosenTimestamp) {
            $newDateTime = Carbon::parse($currentTime);
            if ($this->isGenericTime($newDateTime)) {
                return 'preserved_pending_over_generic_booked';
            }

            return 'preserved_existing_timestamp';
        } else {
            $existingDateTime = Carbon::parse($existingTime);
            if ($this->isGenericTime($existingDateTime)) {
                return 'used_more_precise_booked_timestamp';
            }

            return 'used_new_timestamp';
        }
    }

    /**
     * Upsert account object with proper handling of onboarding-created objects
     */
    protected function upsertAccountObject(array $account): EventObject
    {
        // This is a simplified version - in the real implementation we'd need account data
        // For now, create a basic account object
        $accountName = $this->generateAccountName($account);

        // First, try to find an existing onboarding-created account object
        $accountId = $account['id'] ?? 'unknown';
        $onboardingIntegrationId = 'onboarding_' . $this->integration->group_id . '_' . $accountId;

        $existingObject = EventObject::where('user_id', $this->integration->user_id)
            ->where('concept', 'account')
            ->where('type', 'bank_account')
            ->where('title', $accountName)
            ->whereJsonContains('metadata->integration_id', $onboardingIntegrationId)
            ->first();

        if ($existingObject) {
            // Update the integration ID to point to the real integration
            $existingObject->update([
                'metadata->integration_id' => $this->integration->id,
            ]);

            return $existingObject;
        }

        // Determine account type
        $accountType = $this->mapAccountType($account['cashAccountType'] ?? null);

        // Create new account object
        return EventObject::updateOrCreate(
            [
                'user_id' => $this->integration->user_id,
                'concept' => 'account',
                'type' => 'bank_account',
                'title' => $accountName,
            ],
            [
                'content' => json_encode($account),
                'time' => null,
                'metadata' => [
                    'integration_id' => $this->integration->id,
                    'name' => $accountName,
                    'provider' => $account['institution_id'] ?? 'GoCardless',
                    'account_type' => $accountType,
                    'currency' => $account['currency'] ?? 'GBP',
                    'account_number' => $account['resourceId'] ?? null,
                    'raw' => $account,
                ],
            ]
        );
    }

    /**
     * Upsert counterparty object
     */
    protected function upsertCounterpartyObject(array $tx): EventObject
    {
        $counterpartyName = $tx['creditorName'] ?? $tx['debtorName'] ?? 'Unknown';

        return EventObject::updateOrCreate(
            [
                'user_id' => $this->integration->user_id,
                'concept' => 'money_counterparty',
                'type' => 'transaction_counterparty',
                'title' => $counterpartyName,
            ],
            [
                'content' => json_encode([
                    'creditor_name' => $tx['creditorName'] ?? null,
                    'debtor_name' => $tx['debtorName'] ?? null,
                    'creditor_account' => $tx['creditorAccount'] ?? null,
                    'debtor_account' => $tx['debtorAccount'] ?? null,
                ]),
                'time' => null,
                'metadata' => [
                    'integration_id' => $this->integration->id,
                ],
            ]
        );
    }

    /**
     * Generate a proper account name
     */
    protected function generateAccountName(array $account): string
    {
        $name = $account['ownerName'] ?? $account['name'] ?? '';
        $iban = $account['iban'] ?? '';
        $resourceId = $account['resourceId'] ?? '';

        if (! empty($name)) {
            return $name;
        }

        if (! empty($iban)) {
            // Show last 4 digits of IBAN for privacy
            return 'Account ending in ' . substr($iban, -4);
        }

        if (! empty($resourceId)) {
            return 'Account ' . substr($resourceId, -8);
        }

        return 'Bank Account';
    }

    /**
     * Map GoCardless account types to standard types
     */
    protected function mapAccountType(?string $cashAccountType): string
    {
        return match ($cashAccountType) {
            'CurrentAccount' => 'current_account',
            'SavingsAccount' => 'savings_account',
            'CreditCard' => 'credit_card',
            'InvestmentAccount' => 'investment_account',
            'LoanAccount' => 'loan',
            default => 'other',
        };
    }

    /**
     * Add transaction tags with status and transition handling
     */
    protected function addTransactionTags(Event $event, array $tx, string $status, string $category, bool $isStatusChange, ?Event $existingEvent): void
    {
        $tags = [
            'money',
            'transaction',
            'bank',
            'gocardless',
            $category,
        ];

        // Add status-specific tags
        if ($status === 'pending') {
            $tags[] = 'pending';
        } elseif ($status === 'booked') {
            $tags[] = 'settled';
            // Remove pending tag if it exists (status transition)
            if ($isStatusChange && $existingEvent) {
                $event->detachTag('pending');
            }
        }

        // Add direction-based tags for booked transactions
        if ($status === 'booked') {
            $txAmount = (float) ($tx['transactionAmount']['amount'] ?? 0);
            if ($txAmount < 0) {
                $tags[] = 'debit';
            } else {
                $tags[] = 'credit';
            }
        }

        $event->syncTags($tags);
    }

    /**
     * Add additional metadata blocks
     */
    protected function addAdditionalBlocks(Event $event, array $tx, string $status): void
    {
        // Add status transition information
        if ($status === 'booked') {
            $event->blocks()->create([
                'time' => $event->time,
                'block_type' => 'transaction_status',
                'title' => 'Transaction Status',
                'metadata' => [
                    'status' => $status,
                    'booking_date' => $tx['bookingDate'] ?? null,
                    'value_date' => $tx['valueDate'] ?? null,
                ],
                'value' => null,
                'value_multiplier' => 1,
                'value_unit' => null,
            ]);
        }

        // Add transaction code information
        if (! empty($tx['bankTransactionCode']) || ! empty($tx['proprietaryBankTransactionCode'])) {
            $event->blocks()->create([
                'time' => $event->time,
                'block_type' => 'transaction_code',
                'title' => 'Transaction Code',
                'metadata' => [
                    'bank_transaction_code' => $tx['bankTransactionCode'] ?? null,
                    'proprietary_bank_transaction_code' => $tx['proprietaryBankTransactionCode'] ?? null,
                ],
                'value' => null,
                'value_multiplier' => 1,
                'value_unit' => null,
            ]);
        }
    }

    // Remove the old methods that are no longer needed
    private function processTransaction(array $transaction, string $status): void
    {
        // This method is replaced by processTransactionItem
    }

    private function getAccountObject(): EventObject
    {
        // This method is replaced by upsertAccountObject
        return EventObject::where('user_id', $this->integration->user_id)
            ->where('concept', 'account')
            ->where('type', 'gocardless_account')
            ->whereJsonContains('metadata->integration_id', $this->integration->id)
            ->first()
            ?? throw new Exception('Account object not found');
    }

    private function createCounterpartyObject(array $transaction): EventObject
    {
        $counterpartyName = $this->extractCounterpartyName($transaction);
        $counterpartyDetails = $this->extractCounterpartyDetails($transaction);

        return EventObject::updateOrCreate(
            [
                'user_id' => $this->integration->user_id,
                'concept' => 'counterparty',
                'type' => 'gocardless_counterparty',
                'title' => $counterpartyName,
            ],
            [
                'time' => $this->parseTransactionDate($transaction),
                'content' => $transaction['remittanceInformationUnstructured'] ?? '',
                'metadata' => array_merge($counterpartyDetails, [
                    'integration_id' => $this->integration->id,
                    'transaction_details' => $transaction,
                ]),
            ]
        );
    }

    private function extractCounterpartyName(array $transaction): string
    {
        // Try various fields for counterparty name
        $name = $transaction['creditorName'] ??
                $transaction['debtorName'] ??
                $transaction['ultimateCreditor'] ??
                $transaction['ultimateDebtor'] ??
                $transaction['remittanceInformationUnstructured'] ??
                'Unknown Counterparty';

        return Str::limit($name, 100);
    }

    private function extractCounterpartyDetails(array $transaction): array
    {
        return [
            'creditor_name' => $transaction['creditorName'] ?? null,
            'debtor_name' => $transaction['debtorName'] ?? null,
            'creditor_account' => $transaction['creditorAccount']['iban'] ?? null,
            'debtor_account' => $transaction['debtorAccount']['iban'] ?? null,
            'ultimate_creditor' => $transaction['ultimateCreditor'] ?? null,
            'ultimate_debtor' => $transaction['ultimateDebtor'] ?? null,
        ];
    }

    private function deriveCategory(array $transaction): string
    {
        $purposeCode = $transaction['bankTransactionCode'] ?? '';
        $description = strtolower($transaction['remittanceInformationUnstructured'] ?? '');

        // Map GoCardless transaction codes to categories
        if (str_contains($purposeCode, 'SALA')) {
            return 'salary';
        }
        if (str_contains($purposeCode, 'RDTX')) {
            return 'direct_debit';
        }
        if (str_contains($purposeCode, 'CRTX')) {
            return 'credit_transfer';
        }
        if (str_contains($purposeCode, 'ICDT')) {
            return 'interest_credit';
        }
        if (str_contains($purposeCode, 'IDBT')) {
            return 'interest_debit';
        }
        if (str_contains($purposeCode, 'FEE')) {
            return 'fee';
        }

        // Fallback based on description
        if (str_contains($description, 'salary') || str_contains($description, 'wage')) {
            return 'salary';
        }
        if (str_contains($description, 'direct debit') || str_contains($description, 'dd')) {
            return 'direct_debit';
        }
        if (str_contains($description, 'transfer') || str_contains($description, 'payment')) {
            return 'transfer';
        }

        return 'other';
    }

    private function deriveAction(array $transaction): string
    {
        $amount = (float) ($transaction['transactionAmount']['amount'] ?? 0);

        // Map transaction types to actions
        $purposeCode = $transaction['bankTransactionCode'] ?? '';

        if (str_contains($purposeCode, 'SALA')) {
            return 'salary_received_from';
        }
        if (str_contains($purposeCode, 'RDTX')) {
            return 'direct_debit_to';
        }
        if (str_contains($purposeCode, 'CRTX')) {
            return 'bank_transfer_from';
        }
        if (str_contains($purposeCode, 'ICDT')) {
            return 'interest_earned';
        }
        if (str_contains($purposeCode, 'IDBT')) {
            return 'interest_repaid';
        }
        if (str_contains($purposeCode, 'FEE')) {
            return 'fee_paid_for';
        }

        // Default based on amount sign
        return $amount < 0 ? 'bank_transfer_to' : 'bank_transfer_from';
    }

    private function parseTransactionDate(array $transaction): string
    {
        return $transaction['bookingDate'] ??
               $transaction['valueDate'] ??
               $transaction['transactionDate'] ??
               now()->toDateTimeString();
    }

    private function parseTransactionAmount(array $transaction): int
    {
        $amount = (float) ($transaction['transactionAmount']['amount'] ?? 0);

        return (int) abs($amount * 100); // Convert to cents
    }

    private function parseTransactionCurrency(array $transaction): string
    {
        return $transaction['transactionAmount']['currency'] ?? 'GBP';
    }

    private function buildEventMetadata(array $transaction, string $status, string $category): array
    {
        return [
            'transaction_id' => $transaction['transactionId'] ?? $transaction['internalTransactionId'] ?? null,
            'booking_date' => $transaction['bookingDate'] ?? null,
            'value_date' => $transaction['valueDate'] ?? null,
            'status' => $status,
            'category' => $category,
            'purpose_code' => $transaction['bankTransactionCode'] ?? null,
            'end_to_end_id' => $transaction['endToEndId'] ?? null,
            'mandate_id' => $transaction['mandateId'] ?? null,
            'creditor_id' => $transaction['creditorId'] ?? null,
            'debtor_id' => $transaction['debtorId'] ?? null,
        ];
    }

    private function addTransactionBlocks(Event $event, array $transaction): void
    {
        // Add merchant/location information if available
        if (! empty($transaction['merchant'])) {
            $merchant = $transaction['merchant'];
            $event->blocks()->create([
                'time' => $event->time,
                'block_type' => 'merchant',
                'title' => 'Merchant',
                'metadata' => [
                    'name' => $merchant['name'] ?? null,
                    'category' => $merchant['category'] ?? null,
                    'location' => $merchant['location'] ?? null,
                ],
                'value' => null,
                'value_multiplier' => 1,
                'value_unit' => null,
            ]);
        }

        // Add foreign exchange information if applicable
        $this->addForeignExchangeBlock($event, $transaction);
    }

    private function addForeignExchangeBlock(Event $event, array $transaction): void
    {
        $fxDetails = $transaction['currencyExchange'] ?? null;
        if (! $fxDetails) {
            return;
        }

        $sourceAmount = $fxDetails['sourceCurrency']['amount'] ?? null;
        $sourceCurrency = $fxDetails['sourceCurrency']['currency'] ?? null;
        $targetAmount = $fxDetails['targetCurrency']['amount'] ?? null;
        $targetCurrency = $fxDetails['targetCurrency']['currency'] ?? null;
        $exchangeRate = $fxDetails['exchangeRate'] ?? null;

        if ($sourceAmount && $targetAmount && $sourceCurrency && $targetCurrency) {
            $event->blocks()->create([
                'time' => $event->time,
                'block_type' => 'foreign_exchange',
                'title' => 'Currency Exchange',
                'metadata' => [
                    $sourceCurrency => $sourceAmount,
                    $targetCurrency => $targetAmount,
                    'exchange_rate' => $exchangeRate,
                ],
                'value' => null,
                'value_multiplier' => 1,
                'value_unit' => null,
            ]);
        }
    }

    private function attachTransactionTags(Event $event, array $transaction, string $category): void
    {
        // Add category tag
        $event->attachTag($category);

        // Add transaction type tags
        if (! empty($transaction['bankTransactionCode'])) {
            $event->attachTag($transaction['bankTransactionCode']);
        }

        // Add currency tag
        $currency = $this->parseTransactionCurrency($transaction);
        if ($currency !== 'GBP') {
            $event->attachTag($currency);
        }

        // Add status tag
        $event->attachTag('gocardless');

        // Add credit/debit tag based on amount
        $amount = (float) ($transaction['transactionAmount']['amount'] ?? 0);
        $event->attachTag($amount < 0 ? 'debit' : 'credit');
    }
}
